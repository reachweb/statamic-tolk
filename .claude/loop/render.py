#!/usr/bin/env python3
"""Shows a headless Claude Code session the way the interactive app does.

Reads `claude -p --output-format stream-json --verbose --include-partial-messages` on stdin and
prints the assistant's text as it streams, every tool call with its result, diffs of edits,
background agents, and a status line while the session works. Every event except the
partial-message chunks is appended to --log.
"""

import argparse
import io
import json
import os
import re
import shutil
import sys
import textwrap
import threading
import time

SPINNER = "·✢✳✶✻✽✻✶✳✢"
HIDDEN_TOOLS = {"ToolSearch"}
ESCAPES = re.compile(r"\x1b(\[[0-9;?]*[ -/]*[@-~]|\][^\x07\x1b]*(\x07|\x1b\\)?|.)")
CONTROLS = re.compile(r"[\x00-\x08\x0b-\x1f\x7f]")
DIM, BOLD, RED, GREEN, YELLOW, CODE, ACCENT = "2", "1", "31", "32", "33", "38;5;111", "38;5;173"


def parse_args():
    p = argparse.ArgumentParser(description=__doc__)
    p.add_argument("--log", help="append the session's events to this file")
    p.add_argument("--cwd", default=os.getcwd(), help="show paths relative to this folder")
    p.add_argument("--lines", type=int, default=20, help="lines shown per tool result or diff; 0 shows all")
    p.add_argument("--mode", help="the permission mode the session was asked to use")
    p.add_argument("--color", choices=["auto", "always", "never"], default="auto")
    return p.parse_args()


def duration(secs):
    secs = int(secs)
    if secs < 60:
        return "%ds" % secs
    if secs < 3600:
        return "%dm %02ds" % (secs // 60, secs % 60)
    return "%dh %02dm" % (secs // 3600, secs % 3600 // 60)


def plural(n, word):
    return "%d %s%s" % (n, word, "" if n == 1 else "s")


def clean(text):
    """Removes terminal escape sequences and control characters that tool output may contain."""
    return CONTROLS.sub("", ESCAPES.sub("", text)).expandtabs()


def content_text(content):
    if isinstance(content, str):
        return content
    if isinstance(content, list):
        return "\n".join(c.get("text", "") for c in content if isinstance(c, dict) and c.get("type") == "text")
    return ""


class Out:
    """Terminal writer with colours and a status line that disappears when real output arrives."""

    def __init__(self, color, live):
        self.color = color
        self.live = live
        self.lock = threading.RLock()
        self.status_shown = False
        self.at_line_start = True
        self.blank = True
        self.dead = False
        self.state = "Starting"
        self.state_since = time.time()
        self.last_write = time.time()
        self.thinking_tokens = 0

    def paint(self, text, code):
        if not self.color or not code or not text:
            return text
        return "\033[%sm%s\033[0m" % (code, text)

    def width(self):
        return max(40, shutil.get_terminal_size((120, 24)).columns)

    def write(self, text):
        if not text:
            return
        with self.lock:
            self._clear_status()
            self._raw(text)
            was_line_start = self.at_line_start
            self.at_line_start = text.endswith("\n")
            self.blank = text.endswith("\n\n") or (text == "\n" and was_line_start)
            self.last_write = time.time()

    def line(self, text=""):
        self.write(text + "\n")

    def gap(self):
        with self.lock:
            if not self.at_line_start:
                self.write("\n")
            if not self.blank:
                self.write("\n")

    def set_state(self, state):
        with self.lock:
            if state != self.state:
                self.state = state
                self.state_since = time.time()

    def tick(self):
        with self.lock:
            now = time.time()
            if not self.at_line_start or now - self.last_write < 1:
                return
            detail = duration(now - self.state_since)
            if self.state == "Thinking" and self.thinking_tokens:
                detail += " · %s tokens" % format(self.thinking_tokens, ",")
            frame = SPINNER[int(now * 5) % len(SPINNER)]
            text = "%s %s… (%s)" % (frame, self.state, detail)
            self._raw("\r\033[K" + self.paint(text[: self.width() - 1], ACCENT))
            self.status_shown = True

    def close(self):
        with self.lock:
            self._clear_status()

    def _raw(self, text):
        if self.dead:
            return
        try:
            sys.stdout.write(text)
            sys.stdout.flush()
        except (BrokenPipeError, OSError):
            self.dead = True

    def _clear_status(self):
        if self.status_shown:
            self._raw("\r\033[K")
            self.status_shown = False


class Renderer:
    def __init__(self, out, cwd, lines, mode):
        self.o = out
        self.cwd = cwd.rstrip("/") + "/"
        self.home = os.path.expanduser("~") + "/"
        self.max = max(0, lines)
        self.mode = mode
        self.tools = {}
        self.last_tool = None
        self.streamed = []
        self.text = None
        self.background = {}
        self.started = False

    def handle(self, ev):
        handler = {
            "stream_event": self.on_stream,
            "assistant": self.on_assistant,
            "user": self.on_user,
            "system": self.on_system,
            "result": self.on_result,
            "rate_limit_event": self.on_rate_limit,
        }.get(ev.get("type"))
        if handler:
            handler(ev)

    # Assistant text

    def on_stream(self, ev):
        if ev.get("parent_tool_use_id"):
            return
        e = ev.get("event") or {}
        kind = e.get("type")
        if kind == "message_start":
            self.o.set_state("Working")
        elif kind == "content_block_start":
            block = e.get("content_block") or {}
            if block.get("type") == "text":
                self.text = {"buf": "", "all": "", "first": True, "code": False}
            elif block.get("type") == "thinking":
                self.o.thinking_tokens = 0
                self.o.set_state("Thinking")
            elif block.get("type") == "tool_use":
                self.o.set_state("Preparing " + (block.get("name") or "a tool call"))
        elif kind == "content_block_delta":
            delta = e.get("delta") or {}
            if delta.get("type") == "text_delta" and self.text is not None:
                self.text["buf"] += delta.get("text", "")
                self.text["all"] += delta.get("text", "")
                while "\n" in self.text["buf"]:
                    line, self.text["buf"] = self.text["buf"].split("\n", 1)
                    self.text_line(self.text, line)
            elif delta.get("type") == "thinking_delta":
                self.o.thinking_tokens = delta.get("estimated_tokens") or self.o.thinking_tokens
        elif kind == "content_block_stop" and self.text is not None:
            self.finish_stream()

    def finish_stream(self, full=None):
        """Prints what is left of the streamed text block; `full` is its complete text, when known."""
        st, self.text = self.text, None
        shown = st["all"][: len(st["all"]) - len(st["buf"])]
        rest = full[len(shown):] if full is not None and full.startswith(shown) else st["buf"]
        rest = rest.rstrip("\n")
        for line in rest.split("\n") if rest else []:
            self.text_line(st, line)
        if full is None and st["all"].strip():
            self.streamed.append(st["all"].strip())

    def text_line(self, st, line):
        if st["first"]:
            if not line.strip():
                return
            self.o.gap()
        if line.lstrip().startswith("```"):
            st["code"] = not st["code"]
            pieces = [self.o.paint(line, DIM)]
        elif st["code"]:
            pieces = [self.o.paint(line, CODE)]
        else:
            pieces = self.markdown(line)
        for piece in pieces:
            self.o.line((self.o.paint("⏺", BOLD) + " " if st["first"] else "  ") + piece)
            st["first"] = False

    def markdown(self, line):
        if not line.strip():
            return [""]
        heading = re.match(r"^#{1,6}\s+(.*)$", line)
        if heading and self.o.color:
            return [self.o.paint(p, BOLD) for p in self.wrap(heading.group(1), 0)]
        bullet = re.match(r"^\s*([-*+]|\d+[.)])\s+", line)
        hang = len(bullet.group(0)) if bullet else len(line) - len(line.lstrip())
        return [self.inline(p) for p in self.wrap(line, hang)]

    def wrap(self, text, hang):
        return textwrap.wrap(
            text, width=self.o.width() - 2, subsequent_indent=" " * hang,
            break_on_hyphens=False, replace_whitespace=False,
        ) or [""]

    def inline(self, s):
        if not self.o.color:
            return s
        s = re.sub(r"`([^`]+)`", lambda m: self.o.paint(m.group(1), CODE), s)
        return re.sub(r"\*\*(.+?)\*\*", lambda m: self.o.paint(m.group(1), BOLD), s)

    # Tool calls

    def on_assistant(self, ev):
        parent = ev.get("parent_tool_use_id")
        for block in (ev.get("message") or {}).get("content") or []:
            kind = block.get("type")
            if parent:
                if kind == "tool_use":
                    self.subagent_call(parent, block)
            elif kind == "text":
                text = (block.get("text") or "").strip()
                if self.text is not None:
                    self.finish_stream(block.get("text") or "")
                elif text in self.streamed:
                    self.streamed.remove(text)
                elif text:
                    st = {"first": True, "code": False}
                    for line in text.split("\n"):
                        self.text_line(st, line)
            elif kind == "tool_use":
                self.call(block)

    def call(self, block):
        name, inp = block.get("name") or "?", block.get("input") or {}
        self.tools[block.get("id")] = (name, inp)
        if name in HIDDEN_TOOLS:
            return
        shown, arg, extra = self.title(name, inp)
        self.o.gap()
        self.o.line(self.o.paint("⏺", BOLD) + " " + self.o.paint(shown, BOLD) + "(" + clean(arg) + ")")
        for line in self.limit([(l, DIM) for l in extra]):
            self.o.line("  " + self.paint_line(line))
        self.last_tool = block.get("id")
        if name == "Bash":
            self.o.set_state("Running " + (inp.get("description") or "command"))
        else:
            self.o.set_state("Running " + shown)

    def subagent_call(self, parent, block):
        name, inp = block.get("name") or "?", block.get("input") or {}
        if name in HIDDEN_TOOLS:
            return
        agent = self.tools.get(parent, ("", {}))[1].get("description") or "agent"
        shown, arg, _ = self.title(name, inp)
        self.o.line(self.paint_line(("  ⎿  %s › %s(%s)" % (agent, shown, arg), DIM)))
        self.last_tool = None

    def title(self, name, inp):
        s = lambda key: str(inp.get(key) or "")
        if name == "Bash":
            lines = s("command").split("\n")
            arg = lines[0] + (" · in the background" if inp.get("run_in_background") else "")
            return "Bash", arg, lines[1:]
        if name == "Read":
            arg = self.rel(s("file_path"))
            if inp.get("offset") or inp.get("limit"):
                first = int(inp.get("offset") or 1)
                last = first + int(inp["limit"]) - 1 if inp.get("limit") else "end"
                arg += " · lines %s–%s" % (first, last)
            return "Read", arg, []
        if name in ("Edit", "MultiEdit"):
            return "Update", self.rel(s("file_path")), []
        if name == "NotebookEdit":
            return "Update", self.rel(s("notebook_path")), []
        if name == "Write":
            return "Write", self.rel(s("file_path")), []
        if name in ("Grep", "Glob"):
            arg = 'pattern: "%s"' % s("pattern")
            if inp.get("path"):
                arg += ', path: "%s"' % self.rel(s("path"))
            if inp.get("glob"):
                arg += ', glob: "%s"' % s("glob")
            return "Search", arg, []
        if name == "WebFetch":
            return "Fetch", s("url"), []
        if name == "WebSearch":
            return "Web Search", '"%s"' % s("query"), []
        if name == "Skill":
            return "Skill", (s("skill") + " " + s("args")).strip(), []
        if name in ("Agent", "Task"):
            return "Agent", s("description") + (" · in the background" if inp.get("run_in_background") else ""), []
        for key in ("description", "subject", "file_path", "path", "command", "pattern", "url", "query", "message", "prompt"):
            if inp.get(key):
                text = self.rel(str(inp[key]).strip().split("\n")[0])
                return name, text[:100] + ("…" if len(text) > 100 else ""), []
        return name, "", []

    # Tool results

    def on_user(self, ev):
        content = (ev.get("message") or {}).get("content")
        if ev.get("parent_tool_use_id") or not isinstance(content, list):
            return
        for block in content:
            if not isinstance(block, dict) or block.get("type") != "tool_result":
                continue
            tid = block.get("tool_use_id")
            name, inp = self.tools.get(tid, ("?", {}))
            if name in HIDDEN_TOOLS:
                continue
            lines = self.result(name, inp, block, ev.get("tool_use_result"))
            if tid != self.last_tool:
                shown, arg, _ = self.title(name, inp)
                lines = [("%s(%s)" % (shown, arg), DIM)] + lines
            for i, line in enumerate(lines):
                self.o.line(("  ⎿  " if i == 0 else "     ") + self.paint_line(line))
            self.last_tool = None
        self.o.set_state("Working")

    def result(self, name, inp, block, tur):
        text = content_text(block.get("content")).strip("\n")
        tur = tur if isinstance(tur, dict) else {}
        if block.get("is_error"):
            return self.limit([(l, RED) for l in (text or "Error").split("\n")])
        if name == "Bash":
            if not tur:
                return self.limit([(l, None) for l in text.split("\n")]) if text.strip() else [("(No content)", DIM)]
            stdout = (tur.get("stdout") or "").rstrip("\n")
            stderr = (tur.get("stderr") or "").rstrip("\n")
            lines = [(l, None) for l in stdout.split("\n")] if stdout.strip() else []
            lines += [(l, RED) for l in stderr.split("\n")] if stderr.strip() else []
            if tur.get("backgroundTaskId"):
                lines.append(("Running in the background", DIM))
            if tur.get("interrupted"):
                lines.append(("Interrupted", YELLOW))
            return self.limit(lines) or [("(No content)", DIM)]
        if name == "Read":
            numbers = (tur.get("file") or {}).get("numLines")
            if numbers is not None:
                return [("Read %s" % plural(numbers, "line"), None)]
            return [("Read %s" % ("image" if tur.get("type") == "image" else "file"), None)]
        if name in ("Edit", "MultiEdit", "Write", "NotebookEdit"):
            path = self.rel(tur.get("filePath") or inp.get("file_path") or "")
            if name == "Write" and tur.get("type") == "create":
                body = (tur.get("content") or inp.get("content") or "").rstrip("\n").split("\n")
                numbered = [("%5d  %s" % (i + 1, l), DIM) for i, l in enumerate(body)]
                return [("Wrote %s to %s" % (plural(len(body), "line"), path), None)] + self.limit(numbered)
            patch = tur.get("structuredPatch") or []
            if patch:
                changed = [l for hunk in patch for l in hunk.get("lines", [])]
                adds = sum(1 for l in changed if l.startswith("+"))
                removals = sum(1 for l in changed if l.startswith("-"))
                summary = "Updated %s with %s and %s" % (path, plural(adds, "addition"), plural(removals, "removal"))
                return [(summary, None)] + self.limit(self.diff(patch))
        if name in ("Agent", "Task") and (tur.get("isAsync") or tur.get("status") == "async_launched"):
            return [("Running in the background", DIM)]
        if not text.strip():
            return [("Done", DIM)]
        return self.limit([(l, None) for l in text.split("\n")])

    def diff(self, patch):
        lines = []
        for hunk in patch:
            old, new = hunk.get("oldStart") or 1, hunk.get("newStart") or 1
            if lines:
                lines.append(("    ⋮", DIM))
            for l in hunk.get("lines", []):
                sign, body = l[:1], l[1:]
                if sign == "-":
                    lines.append(("%5d - %s" % (old, body), RED))
                    old += 1
                elif sign == "+":
                    lines.append(("%5d + %s" % (new, body), GREEN))
                    new += 1
                elif sign == "\\":
                    lines.append(("        " + body.strip(), DIM))
                else:
                    lines.append(("%5d   %s" % (new, body), DIM))
                    old += 1
                    new += 1
        return lines

    # Session events

    def on_system(self, ev):
        kind = ev.get("subtype")
        if kind == "init" and not self.started:
            self.started = True
            mode = ev.get("permissionMode")
            details = [d for d in (ev.get("model"), mode and mode + " mode") if d]
            self.o.line(self.o.paint("  " + " · ".join(details), DIM))
            if self.mode and mode and mode != self.mode:
                self.o.line(self.o.paint(
                    "  ⚠ Asked for %s mode, but the session runs in %s mode: tools that need approval will be denied."
                    % (self.mode, mode), YELLOW))
        elif kind == "thinking_tokens":
            self.o.thinking_tokens = ev.get("estimated_tokens") or self.o.thinking_tokens
            self.o.set_state("Thinking")
        elif kind == "status" and ev.get("status") == "compacting":
            self.o.set_state("Compacting the conversation")
        elif kind == "compact_boundary":
            self.o.gap()
            self.o.line(self.o.paint("  ── conversation compacted ──", DIM))
        elif kind == "permission_denied":
            self.o.line(self.o.paint("  ⚠ Permission denied for %s: %s" % (ev.get("tool_name"), ev.get("message") or ""), YELLOW))
        elif kind == "task_started":
            kind_name = "Agent" if ev.get("task_type") == "local_agent" else "Background task"
            self.background[ev.get("task_id")] = (kind_name, ev.get("description") or ev.get("task_id"))
        elif kind == "task_notification":
            kind_name, desc = self.background.pop(ev.get("task_id"), ("Background task", ev.get("description") or ev.get("task_id")))
            status = ev.get("status") or "finished"
            self.o.gap()
            self.o.line(self.o.paint("⏺", BOLD) + ' %s "%s" %s' % (kind_name, desc, status))
            summary = (ev.get("summary") or "").strip().split("\n")[0]
            if summary and summary != desc:
                self.o.line("  ⎿  " + self.paint_line((summary, RED if status == "failed" else DIM)))

    def on_result(self, ev):
        if ev.get("is_error") or ev.get("subtype") not in (None, "success"):
            message = ev.get("result") or ", ".join(str(e) for e in ev.get("errors") or []) or ev.get("subtype")
            self.o.gap()
            self.o.line(self.o.paint("⏺ The session ended with an error (%s): %s" % (ev.get("subtype"), message), RED))
        elif self.background:
            self.o.set_state("Waiting for background tasks")
        else:
            self.o.set_state("Finishing")

    def on_rate_limit(self, ev):
        info = ev.get("rate_limit_info") or {}
        if info.get("status") in (None, "allowed"):
            return
        resets = info.get("resetsAt")
        when = time.strftime("%H:%M", time.localtime(resets)) if resets else "unknown"
        self.o.line(self.o.paint("  ⚠ Usage limit (%s): %s, resets at %s" % (info.get("rateLimitType"), info.get("status"), when), YELLOW))

    # Helpers

    def limit(self, lines):
        if self.max and len(lines) > self.max:
            return lines[: self.max] + [("… +%s" % plural(len(lines) - self.max, "line"), DIM)]
        return lines

    def paint_line(self, line):
        text, code = clean(line[0]), line[1]
        room = self.o.width() - 6
        if self.max and len(text) > room:
            text = text[: room - 1] + "…"
        return self.o.paint(text, code)

    def rel(self, path):
        if path.startswith(self.cwd):
            return path[len(self.cwd):]
        if path.startswith(self.home):
            return "~/" + path[len(self.home):]
        return path


def main():
    args = parse_args()
    stdin = io.TextIOWrapper(sys.stdin.buffer, encoding="utf-8", errors="replace")
    sys.stdout = io.TextIOWrapper(sys.stdout.buffer, encoding="utf-8", errors="replace")
    tty = sys.stdout.isatty()
    color = args.color == "always" or (args.color == "auto" and tty and not os.environ.get("NO_COLOR"))
    out = Out(color, live=tty)
    renderer = Renderer(out, args.cwd, args.lines, args.mode)
    log = open(args.log, "a", encoding="utf-8") if args.log else None

    stop = threading.Event()

    def spin():
        while not stop.wait(0.2):
            out.tick()

    if out.live:
        threading.Thread(target=spin, daemon=True).start()

    reported = False
    try:
        for raw in iter(stdin.readline, ""):
            try:
                ev = json.loads(raw)
            except ValueError:
                if raw.strip():
                    out.write(raw if raw.endswith("\n") else raw + "\n")
                continue
            if not isinstance(ev, dict):
                continue
            if log and ev.get("type") != "stream_event":
                log.write(raw if raw.endswith("\n") else raw + "\n")
                log.flush()
            try:
                renderer.handle(ev)
            except Exception as error:  # the display must never stop the session or the log
                if not reported:
                    out.line(out.paint("  (display error: %r; the log is complete)" % error, DIM))
                    reported = True
    except KeyboardInterrupt:
        pass
    finally:
        stop.set()
        out.close()
        if log:
            log.close()


if __name__ == "__main__":
    main()
