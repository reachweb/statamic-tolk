<?php

return [
    'field' => [
        'type' => 'markdown',
        'display' => 'Body',
        'antlers' => true,
    ],
    'value' => <<<'MD'
# Hiking the Samaria Gorge

The Samaria Gorge is **16 kilometres** long and runs from the Omalos plateau down to the *Libyan Sea* at Agia Roumeli. Most people walk it in five to seven hours.  
Bring water, a hat and good shoes.

## Before you go

The gorge is open from **1 May** to **31 October**, weather permitting. Check the [national park](https://www.samaria.gr/ "Samaria National Park") on the morning of your walk, or ask at [the front desk](statamic://entry::51c8a58f-8a3d-47fa-90cc-91f543aa618f).

1. Book the early bus to Omalos.
2. Buy your ticket at the entrance (€10, cash only).
3. Walk down, never up:
   - the path drops 1,250 metres;
   - the last boat from Agia Roumeli leaves at 17:30.

> Start early. The first two hours are in the shade, and the narrow *Sideroportes* gate is quiet before ten.
>
> — Katerina, who has walked it forty times

![The Iron Gates of the gorge](statamic://asset::assets::samaria-gates.jpg "Sideroportes, the narrowest point")

## What to pack

| Item | Why |
| --- | --- |
| Water | There are springs, but carry 1.5 l |
| Shoes | The riverbed is **loose stone** |
| Cash | For the boat and the taverna |

Our packing list, ready for your notes app:

```text
water 1.5 l
hat

sun cream
```

~~~yaml
# Paste into the transfer form
transfer: omalos
pickup: "06:15"
~~~

Use the code `SAMARIA26` for the hotel's packed lunch, or see the [lunch menu][menu].

<div class="notice">
Closed on days of high wind: <strong>call before you leave</strong>.
</div>

<!-- Last checked with the park office in March 2026. -->

## After the walk

The boat from Agia Roumeli stops in Sougia and Chora Sfakion, where the bus back to Chania waits for it. You will be home in time for dinner; the kitchen keeps a table for walkers until 22:30.

If your knees disagree, the spa has a *sports massage* for exactly this day. Call {{ site_settings:phone }} to book it.

---

Questions? Write to <info@kyma-bay.example> or visit <https://www.kyma-bay.example/contact>.

[menu]: statamic://entry::ac2b1a25-e593-4b80-94a2-a97336a0b085 "Packed lunch menu"
MD,
];
