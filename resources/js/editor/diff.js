// A word-level diff of a reviewer's correction (T7.5): the longest common subsequence of whitespace-separated words,
// where each placeholder (`<1>`, `</1>`, `<x1/>`, `<br/>`) is a word of its own, so a moved mark shows as moved tags.

const PIECES = /(<\/?\d+>|<x\d+\/>|<br\/>|\s+)/;
const PLACEHOLDER = /^(?:<\/?\d+>|<x\d+\/>|<br\/>)$/;
const SPACE = /^\s+$/;

// Above this many table cells a diff is too slow to compute while the dialog opens, so the texts count as replaced.
export const MAX_CELLS = 250_000;

// The text as `{ text, kind }` pieces: `word`, `placeholder` or `space`. Joined, they give the text back.
export function tokenize(text) {
    return String(text ?? '')
        .split(PIECES)
        .filter((piece) => piece !== '')
        .map((piece) => ({ text: piece, kind: SPACE.test(piece) ? 'space' : PLACEHOLDER.test(piece) ? 'placeholder' : 'word' }));
}

// The indexes of the words of `a` and of `b` that both keep, by the longest common subsequence.
function common(a, b) {
    const keptA = new Set();
    const keptB = new Set();

    if (a.length * b.length > MAX_CELLS) {
        return [keptA, keptB];
    }

    const width = b.length + 1;
    const lengths = new Uint32Array((a.length + 1) * width);

    for (let i = a.length - 1; i >= 0; i--) {
        for (let j = b.length - 1; j >= 0; j--) {
            lengths[i * width + j] =
                a[i] === b[j] ? lengths[(i + 1) * width + j + 1] + 1 : Math.max(lengths[(i + 1) * width + j], lengths[i * width + j + 1]);
        }
    }

    for (let i = 0, j = 0; i < a.length && j < b.length; ) {
        if (a[i] === b[j]) {
            keptA.add(i++);
            keptB.add(j++);
        } else if (lengths[(i + 1) * width + j] >= lengths[i * width + j + 1]) {
            i++;
        } else {
            j++;
        }
    }

    return [keptA, keptB];
}

// Marks the words outside `kept` as changed, and a space between two changed words too, so a changed phrase reads
// as one run.
function mark(pieces, kept) {
    let word = 0;
    const parts = pieces.map((piece) => ({ ...piece, changed: piece.kind !== 'space' && !kept.has(word++) }));

    parts.forEach((part, index) => {
        if (part.kind === 'space') {
            part.changed = Boolean(parts[index - 1]?.changed && parts[index + 1]?.changed);
        }
    });

    return parts;
}

// `before` and `after` as lists of `{ text, kind, changed }`: in `before` the words the correction removed, in
// `after` the words it added.
export function diffWords(before, after) {
    const a = tokenize(before);
    const b = tokenize(after);
    const words = (pieces) => pieces.filter((piece) => piece.kind !== 'space').map((piece) => piece.text);
    const [keptA, keptB] = common(words(a), words(b));

    return { before: mark(a, keptA), after: mark(b, keptB) };
}
