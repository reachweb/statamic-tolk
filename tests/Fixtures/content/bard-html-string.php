<?php

// Bard's own HTML (save_html without sets), as Bard::process() renders it with Statamic's image and link URLs.
return [
    'field' => [
        'type' => 'bard',
        'display' => 'Body',
        'save_html' => true,
        'container' => 'assets',
        'buttons' => [
            'h2', 'h3', 'bold', 'italic', 'small', 'superscript', 'code', 'codeblock', 'quote', 'anchor',
            'unorderedlist', 'orderedlist', 'table', 'image', 'horizontalrule', 'aligncenter',
        ],
    ],
    'value' => <<<'HTML'
<h2>Our new spa</h2><p>The spa opens on <strong>1 April</strong> in the old olive press. Book a treatment at <a href="statamic://entry::51c8a58f-8a3d-47fa-90cc-91f543aa618f">the front desk</a> or read the <a target="_blank" href="statamic://asset::assets::spa-prices.pdf" title="Spa prices for 2026 (PDF)">price list</a>.</p><p style="text-align: center;"><em>Open daily</em><br>10:00–20:00, kids &lt; 12 &amp; guests over 70 with a companion</p><h3>Treatments</h3><ul><li><p>Olive oil massage, 60 minutes</p></li><li><p>Sea salt scrub</p><ol start="1"><li><p>with <strong><em>Cretan</em></strong> honey</p></li><li><p>with thyme</p></li></ol></li></ul><blockquote><p>The best hour of our holiday. <small>Anna, Vienna</small></p></blockquote><table><tbody><tr><th rowspan="1" colspan="1"><p>Treatment</p></th><th rowspan="1" colspan="1"><p>Price</p></th></tr><tr><td rowspan="1" colspan="1"><p>Massage</p></td><td rowspan="1" colspan="1"><p>€80</p></td></tr></tbody></table><hr><p><img src="statamic://asset::assets::spa-pool.jpg" alt="The indoor pool under the stone arches"></p><p>Room size 45 m<sup>2</sup>, code <code>SPA-10</code> for 10% off, and <em>eleven o&#039;clock</em> is never too late for coffee.</p><pre><code>&lt;div class=&quot;booking&quot;&gt;
  {{ partial:booking }}
&lt;/div&gt;</code></pre>
HTML,
];
