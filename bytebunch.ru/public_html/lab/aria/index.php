<?php
header('Content-Type: text/html; charset=utf-8');
$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
$host = preg_replace('/[^A-Za-z0-9.\-:]/', '', $_SERVER['HTTP_HOST'] ?? 'localhost');
$root = rtrim(str_replace('\\', '/', dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '/x/x'))), '/');
$site = htmlspecialchars(($https ? 'https' : 'http') . '://' . $host . $root, ENT_QUOTES, 'UTF-8');
?>
<!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>Aria — движок тюнера и тесты</title>
<meta name="description" content="Aria — движок распознавания высоты звука в Sionika. Тесты против GuitarTuna и 18 открытых алгоритмов.">
<meta name="theme-color" content="#000000">
<meta property="og:type" content="website">
<meta property="og:site_name" content="ByteBunch • Lab">
<meta property="og:locale" content="ru_RU">
<meta property="og:title" content="Aria — движок тюнера и тесты">
<meta property="og:description" content="Aria — движок распознавания высоты звука в Sionika. Тесты против GuitarTuna и 18 открытых алгоритмов.">
<meta property="og:url" content="<?= $site ?>/aria/">
<meta property="og:image" content="<?= $site ?>/assets/og-aria.jpg">
<meta property="og:image:width" content="1200">
<meta property="og:image:height" content="630">
<meta name="twitter:card" content="summary_large_image">
<link rel="icon" href="../assets/favicon.svg" type="image/svg+xml">
<link rel="icon" href="../assets/favicon-32.png" sizes="32x32" type="image/png">
<link rel="apple-touch-icon" href="../assets/apple-touch-icon.png">
<link rel="preload" href="../assets/fonts/golos-text-cyrillic.woff2" as="font" type="font/woff2" crossorigin>
<link rel="stylesheet" href="../assets/fonts.css">

<link rel="stylesheet" href="../assets/lab.css">
<style>
/* Aria: a research note on the lab plane. Charts sit directly on black, framed by hairlines; every scale is cents or %. */
:root { --tick: #3a3a3a; --bar: #d8d8d8; }
.a-hero { padding-block: calc(var(--nav-h) + 72px) 72px; position: relative; overflow: hidden;
  background-image: linear-gradient(var(--line) 1px, transparent 1px), linear-gradient(90deg, var(--line) 1px, transparent 1px); background-size: 66px 66px; background-position: center -1px; }
.a-hero::after { content: ""; position: absolute; inset: 0; background: radial-gradient(120% 90% at 30% 30%, rgba(0,0,0,0) 30%, #000 85%); pointer-events: none; }
.a-hero .wrap { position: relative; z-index: 1; display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); gap: 48px; align-items: end; }
.a-hero .crumb { font: 400 13px var(--f-mono); color: var(--muted); }
.a-hero .crumb a { color: var(--ink-2); text-decoration: none; }
.a-hero h1 { margin-top: 18px; font: 600 clamp(84px, 13vw, 176px)/0.86 var(--f-wide); letter-spacing: -0.035em; color: var(--aria); display: flex; align-items: flex-end; gap: 0.12em; flex-wrap: wrap; }
.a-hero h1 small { font: 500 0.26em/1 var(--f-wide); color: var(--ink); letter-spacing: -0.01em; padding-bottom: 0.12em; }
.a-hero .what { font: 500 clamp(20px, 2vw, 26px)/1.3 var(--f-sans); margin-top: 26px; }
.a-hero .lede { margin-top: 14px; }
.a-hero .meta { display: flex; flex-wrap: wrap; gap: 6px 22px; margin-top: 26px; font: 400 13px var(--f-mono); color: var(--muted); }
.a-hero .acts { display: flex; flex-wrap: wrap; gap: 12px; margin-top: 30px; }
.readout { border-top: 1px solid var(--line-2); padding-top: 18px; }
.readout svg { display: block; width: 100%; height: auto; overflow: visible; }
.readout p { font-size: 14px; color: var(--muted); margin-top: 10px; max-width: 46ch; }
@media (prefers-reduced-motion: no-preference) {
  .a-hero h1, .a-hero .what, .a-hero .lede, .a-hero .meta, .a-hero .acts, .readout { animation: up .9s var(--ease) both; }
  .a-hero .what { animation-delay: .12s; } .a-hero .lede { animation-delay: .2s; } .a-hero .meta { animation-delay: .28s; } .a-hero .acts { animation-delay: .34s; } .readout { animation-delay: .3s; }
}
@keyframes up { from { opacity: 0; transform: translateY(14px); } to { opacity: 1; transform: none; } }

/* summary numbers */
.stats { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); border-top: 1px solid var(--line); border-bottom: 1px solid var(--line); }
.stat { padding: 30px 28px 30px 0; display: grid; gap: 10px; align-content: start; }
.stat + .stat { border-left: 1px solid var(--line); padding-left: 28px; }
.stat .v { font: 600 clamp(44px, 5vw, 64px)/1 var(--f-sans); letter-spacing: -0.03em; }
.stat .v span { font-size: .45em; font-weight: 500; color: var(--ink-2); margin-left: 4px; letter-spacing: 0; }
.stat .l { color: var(--ink-2); font-size: 15.5px; max-width: 30ch; }
.stat .vs { font: 400 13px var(--f-mono); color: var(--muted); display: flex; align-items: center; gap: 8px; }
.key { width: 12px; height: 2px; display: inline-block; flex: none; }
.cent { margin-top: 26px; display: grid; grid-template-columns: auto minmax(0, 1fr); gap: 18px; align-items: baseline; color: var(--ink-2); font-size: 15px; max-width: 760px; }
.cent b { font: 500 14px var(--f-mono); color: var(--ink); }

/* how it works */
.steps { list-style: none; margin: 0; padding: 0; display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); border-top: 1px solid var(--line); }
.steps li { padding: 26px 24px 8px 0; display: grid; gap: 14px; align-content: start; }
.steps li + li { border-left: 1px solid var(--line); padding-left: 24px; }
.steps .n { font: 400 13px var(--f-mono); color: var(--muted); }
.steps svg { width: 100%; height: 76px; display: block; overflow: visible; }
.steps h3 { font: 500 18px/1.3 var(--f-sans); }
.steps p { color: var(--ink-2); font-size: 15px; }

/* charts */
.fig { margin: 0; border-top: 1px solid var(--line); padding-top: 22px; display: grid; gap: 14px; min-width: 0; }
.fig-head { display: flex; flex-wrap: wrap; justify-content: space-between; gap: 10px 24px; align-items: baseline; }
.fig-title { font: 500 17px/1.35 var(--f-sans); }
.fig-sub { font-size: 14px; color: var(--muted); max-width: 70ch; }
.legend { display: flex; flex-wrap: wrap; gap: 6px 4px; font-size: 14px; color: var(--ink-2); }
.legend button { font: inherit; color: inherit; background: none; border: 1px solid transparent; padding: 5px 10px; display: inline-flex; align-items: center; gap: 8px; cursor: pointer; }
.legend button[aria-pressed="true"] { border-color: var(--line-2); color: var(--ink); }
.legend i { width: 10px; height: 10px; display: inline-block; }
.legend i.dot { border-radius: 50%; }
.plot { min-width: 0; }
.plot svg { display: block; overflow: visible; }
.grid line { stroke: var(--line); }
.ax text, .lbl { fill: var(--muted); font: 400 12px var(--f-mono); }
.lbl.ink { fill: var(--ink); } .lbl.ink2 { fill: var(--ink-2); }
.lbl { paint-order: stroke; stroke: var(--bg); stroke-width: 4px; stroke-linejoin: round; }
.hit { fill: transparent; }
.hit:focus-visible { stroke: #fff; stroke-width: 1.5; outline: none; }
.dim { opacity: .18; transition: opacity .3s; }
svg g[data-s] { transition: opacity .3s; }

/* bass explorer */
.explore { display: grid; grid-template-columns: minmax(0, 360px) minmax(0, 1fr); gap: 40px; align-items: start; }
.ctrl { display: grid; gap: 22px; }
.ctrl label, .ctrl .lab { font: 400 13px var(--f-mono); color: var(--muted); display: block; margin-bottom: 10px; }
.seg { display: flex; flex-wrap: wrap; border: 1px solid var(--line-2); width: fit-content; }
.seg button { font: 500 14px var(--f-mono); color: var(--ink-2); background: none; border: 0; padding: 9px 12px; cursor: pointer; }
.seg button + button { border-left: 1px solid var(--line-2); }
.seg button[aria-pressed="true"] { background: var(--ink); color: #000; }
input[type=range] { width: 100%; accent-color: #fff; }
.rd { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); border-top: 1px solid var(--line); }
.rd div { padding-top: 14px; display: grid; gap: 4px; }
.rd div + div { border-left: 1px solid var(--line); padding-left: 16px; }
.rd b { font: 600 34px/1 var(--f-sans); letter-spacing: -0.02em; font-variant-numeric: tabular-nums; }
.rd span { font-size: 13.5px; color: var(--ink-2); }
.rd small { font: 400 12px var(--f-mono); color: var(--muted); }

/* race */
.race-ctl { display: flex; align-items: center; gap: 14px; }
.replay { font: 500 14px var(--f-sans); color: var(--ink); background: none; border: 1px solid var(--line-2); padding: 8px 14px; cursor: pointer; }
.replay:hover { border-color: var(--ink); }
.clock { font: 500 14px var(--f-mono); color: var(--ink-2); font-variant-numeric: tabular-nums; }

/* tables */
.tbox { overflow-x: auto; border-top: 1px solid var(--line); }
table.t { border-collapse: collapse; width: 100%; font-size: 14.5px; min-width: 520px; }
table.t.fit { min-width: 0; }
.t th, .t td { padding: 13px 14px 13px 0; text-align: left; vertical-align: top; border-bottom: 1px solid var(--line); }
.t thead th { font: 400 13px/1.4 var(--f-mono); color: var(--muted); }
.t thead th small { display: block; }
.t th[scope="row"] { font-weight: 500; color: var(--ink); }
.t th[scope="row"] small { display: block; font-weight: 400; color: var(--muted); font-size: 13px; margin-top: 2px; }
.t td { font-family: var(--f-mono); font-variant-numeric: tabular-nums; color: var(--ink-2); white-space: nowrap; }
.t td.best { color: var(--ink); }
.t td.best::before { content: ""; display: inline-block; width: 6px; height: 6px; background: var(--ink); margin-right: 8px; vertical-align: 2px; }
.t tr.cat th { font: 400 12.5px var(--f-mono); color: var(--muted); padding-top: 22px; }
.t tr.mine th[scope="row"] { color: var(--aria); }

.cons { list-style: none; margin: 0; padding: 0; border-top: 1px solid var(--line); }
.cons li { display: grid; grid-template-columns: 220px minmax(0, 1fr); gap: 20px; padding: 18px 0; border-bottom: 1px solid var(--line); }
.cons b { font-weight: 500; }
.cons p { color: var(--ink-2); font-size: 15.5px; }

details > summary { cursor: pointer; width: fit-content; font: 500 15px var(--f-sans); color: var(--ink); list-style: none; display: inline-flex; gap: 10px; align-items: center; border-bottom: 1px solid var(--line-2); padding-bottom: 3px; }
details > summary::-webkit-details-marker { display: none; }
details > summary::after { content: "+"; font-family: var(--f-mono); color: var(--muted); }
details[open] > summary::after { content: "−"; }
details[open] > summary { margin-bottom: 18px; }

ul.method { list-style: none; margin: 0; padding: 0; display: grid; border-top: 1px solid var(--line); }
ul.method li { display: grid; grid-template-columns: 220px minmax(0, 1fr); gap: 20px; padding: 16px 0; border-bottom: 1px solid var(--line); color: var(--ink-2); font-size: 15.5px; }
ul.method b { color: var(--ink); font-weight: 500; }

.tip { position: fixed; z-index: 60; pointer-events: none; background: #fff; color: #000; padding: 8px 10px; font-size: 12.5px; max-width: 260px; display: grid; gap: 2px; }
.tip b { font: 500 15px var(--f-mono); }
.blk { display: grid; gap: 28px; }
.blk + .blk { margin-top: 64px; }
h3.sub { font: 600 clamp(22px, 2.2vw, 28px)/1.2 var(--f-wide); letter-spacing: -0.015em; }

@media (max-width: 960px) {
  .a-hero .wrap, .explore { grid-template-columns: minmax(0, 1fr); }
  .steps { grid-template-columns: repeat(2, minmax(0, 1fr)); }
  .steps li:nth-child(3) { border-left: 0; padding-left: 0; }
  .steps li:nth-child(n+3) { border-top: 1px solid var(--line); }
}
@media (max-width: 640px) {
  .a-hero { padding-block: calc(var(--nav-h) + 40px) 48px; }
  .stats { grid-template-columns: minmax(0, 1fr); }
  .stat + .stat { border-left: 0; padding-left: 0; border-top: 1px solid var(--line); }
  .steps { grid-template-columns: minmax(0, 1fr); }
  .steps li + li { border-left: 0; padding-left: 0; border-top: 1px solid var(--line); }
  .cons li, ul.method li { grid-template-columns: minmax(0, 1fr); gap: 6px; }
  .t.fit th, .t.fit td { padding-right: 8px; font-size: 13px; }
  .t.fit th[scope="row"] { min-width: 110px; }
}
</style>
</head>
<body>
<nav class="nav" aria-label="Основное меню"><div class="wrap"><a class="brand" href="../" aria-label="ByteBunch • Lab, на главную"><svg aria-hidden="true" focusable="false" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 4659 1828" fill="currentColor"><g transform="translate(0.000000,779.000000) scale(0.100000,-0.100000)"><path d="M31 7779 l-34 -10 6 -67 c9 -106 26 -231 41 -302 8 -36 22 -123 31 -195 9 -71 22 -157 30 -190 7 -33 21 -118 29 -190 9 -71 21 -155 27 -185 26 -138 49 -288 49 -318 0 -18 11 -88 25 -155 14 -67 30 -167 35 -222 5 -55 18 -138 29 -185 12 -47 25 -133 31 -192 6 -58 21 -161 35 -227 14 -66 25 -137 25 -158 0 -21 9 -87 20 -148 32 -181 38 -216 49 -315 6 -52 20 -140 31 -195 10 -55 24 -149 30 -210 6 -60 20 -147 31 -193 24 -105 24 -164 0 -283 -10 -52 -24 -141 -31 -199 -6 -58 -20 -150 -30 -205 -10 -55 -24 -147 -30 -205 -6 -58 -20 -150 -30 -205 -11 -55 -24 -145 -30 -200 -6 -55 -22 -161 -35 -235 -14 -74 -30 -187 -36 -250 -6 -63 -19 -153 -30 -199 -11 -46 -24 -134 -29 -195 -6 -61 -16 -142 -24 -181 -19 -98 -33 -189 -52 -345 -9 -74 -21 -145 -25 -158 -5 -13 -16 -83 -24 -155 -8 -73 -21 -170 -30 -217 -15 -84 -26 -157 -46 -320 -6 -47 -17 -116 -25 -155 -19 -92 -44 -289 -44 -342 0 -32 5 -45 20 -53 14 -8 961 -10 3253 -7 2997 3 3237 4 3307 20 87 20 164 34 235 43 28 3 77 15 110 26 33 11 87 26 120 33 57 12 210 69 355 132 310 134 657 416 827 671 122 184 235 418 261 539 6 27 20 69 31 95 11 25 23 68 26 95 4 26 19 95 33 153 26 102 27 114 27 405 0 291 -1 303 -27 410 -15 61 -30 130 -34 155 -3 25 -16 68 -29 95 -12 28 -25 63 -29 80 -19 77 -124 276 -189 359 -174 223 -362 392 -577 520 -69 41 -374 181 -415 191 -55 13 -173 66 -179 81 -4 10 14 26 55 52 140 85 253 171 354 269 133 128 277 329 350 486 75 163 85 186 93 223 5 22 19 62 30 89 11 28 23 70 27 95 4 25 19 92 32 150 61 253 30 771 -61 1003 -13 34 -28 78 -31 97 -23 110 -195 397 -318 529 -154 166 -182 186 -517 391 -45 28 -163 81 -210 95 -14 4 -47 19 -75 33 -59 29 -298 92 -353 92 -21 0 -69 9 -107 19 -159 44 -169 44 -3325 47 -1964 2 -3017 -1 -3039 -7z m4970 -1805 c121 -33 246 -118 309 -211 46 -67 55 -89 85 -207 l29 -109 -18 -85 c-22 -110 -95 -259 -161 -332 -30 -33 -145 -106 -201 -128 -98 -39 -174 -42 -1119 -42 -855 0 -914 1 -934 18 -21 17 -50 144 -51 220 0 18 -13 97 -29 175 -16 78 -32 176 -36 217 -3 41 -15 112 -26 157 -11 44 -26 134 -34 199 -13 109 -13 119 2 136 16 18 56 19 1062 15 1013 -3 1048 -4 1122 -23z m439 -2878 c116 -32 204 -76 274 -137 60 -53 158 -210 167 -269 3 -25 17 -83 29 -130 53 -203 -21 -438 -187 -592 -62 -57 -105 -80 -249 -129 l-112 -39 -1269 0 c-1058 0 -1272 2 -1288 14 -17 12 -18 19 -7 97 7 46 17 125 22 174 6 50 17 119 25 155 8 36 22 124 30 195 9 72 24 173 35 225 11 52 24 136 30 185 6 50 15 119 20 155 5 36 10 68 10 71 0 4 9 18 21 33 l21 26 1161 -2 1162 -3 105 -29z M16610 7760 c-691 -4 -930 -8 -946 -17 -27 -16 -87 -87 -220 -263 -61 -80 -133 -174 -160 -208 -27 -35 -58 -76 -69 -92 -11 -15 -36 -47 -55 -72 -19 -25 -84 -109 -145 -188 -60 -79 -126 -163 -145 -188 -19 -24 -57 -75 -85 -112 -27 -37 -79 -104 -115 -150 -36 -46 -90 -115 -120 -155 -118 -154 -277 -360 -307 -399 -18 -23 -41 -52 -50 -66 -10 -14 -70 -92 -134 -175 -63 -82 -162 -211 -219 -285 -124 -162 -158 -200 -181 -200 -15 0 -172 183 -229 266 -8 12 -49 66 -90 120 -41 53 -110 142 -153 198 -197 257 -237 307 -407 527 -74 96 -161 210 -195 254 -33 44 -94 123 -135 175 -128 162 -178 228 -216 285 -15 22 -44 60 -64 85 -20 25 -76 97 -125 160 -49 63 -112 142 -140 176 -27 33 -90 113 -139 177 -56 74 -101 122 -121 131 -51 24 -2825 24 -2849 0 -31 -30 -21 -53 80 -179 30 -38 86 -110 124 -160 38 -49 107 -139 155 -200 215 -276 254 -325 314 -405 35 -47 89 -116 120 -155 31 -38 82 -104 114 -145 31 -41 86 -113 123 -160 36 -47 80 -104 97 -126 18 -23 77 -99 132 -169 55 -70 111 -143 125 -161 14 -19 64 -85 112 -146 49 -62 133 -171 189 -243 55 -71 115 -148 132 -171 18 -23 73 -95 124 -160 104 -135 278 -359 326 -420 18 -22 67 -87 110 -145 102 -137 203 -267 307 -396 47 -59 103 -129 124 -157 22 -28 90 -117 152 -197 62 -80 128 -167 146 -192 33 -46 190 -250 258 -333 98 -120 161 -206 170 -229 6 -17 10 -556 10 -1528 0 -1191 3 -1507 13 -1527 l13 -25 1282 -3 c1130 -2 1286 0 1312 13 l30 15 2 1528 3 1527 28 43 c15 24 88 121 161 215 198 255 252 325 304 395 26 35 85 111 132 170 47 59 105 134 130 167 25 33 74 97 110 142 36 45 87 110 114 145 28 34 73 92 101 128 29 36 66 85 83 110 43 60 66 91 133 175 30 38 97 124 147 190 51 66 108 140 128 165 19 25 97 125 172 224 75 98 167 217 204 265 37 47 81 104 97 126 17 22 71 92 120 155 50 63 104 133 120 155 50 65 166 213 254 323 45 57 93 118 107 136 14 18 45 60 70 92 25 33 54 71 65 86 23 31 217 284 255 333 14 18 68 88 120 156 52 68 121 157 153 199 32 41 76 98 97 125 117 151 130 171 130 197 0 16 -7 31 -17 36 -25 13 -821 18 -1903 12z M20927 7721 c-2260 -6 -2299 -6 -2313 -25 -12 -17 -14 -191 -14 -1156 l0 -1137 21 -19 c20 -18 62 -19 1383 -24 l1361 -5 5 -2656 c4 -2231 7 -2659 19 -2672 21 -27 2575 -25 2608 1 l23 17 1 2638 c1 2583 1 2639 20 2658 19 19 54 19 1378 19 l1358 0 27 21 26 20 0 1140 c0 1126 0 1139 -20 1159 -20 20 -33 20 -1578 20 -857 0 -1660 1 -1783 3 -123 2 -1258 1 -2522 -2z M28108 7722 c-790 -2 -797 -2 -808 -22 -13 -24 -6 -132 15 -235 25 -121 45 -253 45 -303 0 -26 5 -65 10 -87 19 -81 50 -278 50 -323 0 -26 7 -78 15 -116 21 -101 43 -241 49 -321 3 -38 17 -124 30 -190 13 -66 27 -158 30 -205 4 -47 17 -141 30 -210 14 -69 29 -175 35 -236 5 -61 19 -153 30 -205 11 -52 27 -161 36 -242 9 -81 20 -159 25 -173 5 -13 16 -86 25 -162 8 -75 17 -157 20 -182 3 -25 11 -76 19 -115 22 -106 46 -266 46 -306 0 -19 7 -60 16 -90 21 -70 21 -174 1 -269 -9 -41 -23 -138 -32 -215 -9 -77 -22 -167 -30 -200 -8 -33 -21 -125 -30 -205 -9 -80 -23 -173 -31 -208 -8 -34 -21 -126 -30 -205 -8 -78 -24 -184 -35 -235 -11 -51 -24 -141 -30 -200 -5 -59 -21 -165 -34 -237 -14 -71 -29 -178 -35 -237 -5 -59 -18 -150 -30 -203 -11 -54 -24 -143 -30 -199 -9 -100 -18 -156 -46 -293 -7 -40 -14 -93 -14 -119 0 -49 -8 -100 -41 -262 -10 -51 -19 -112 -19 -137 0 -47 -22 -199 -45 -305 -24 -114 -29 -225 -10 -244 15 -14 91 -16 828 -16 6432 -1 6600 0 6623 15 13 8 26 29 29 47 3 18 5 477 3 1020 -3 932 -4 988 -21 1005 -17 17 -125 18 -2309 20 -2039 3 -2294 4 -2315 18 -27 18 -27 29 -8 110 8 33 15 81 15 107 0 47 6 82 41 240 10 48 19 106 19 130 1 24 9 77 18 118 15 63 22 77 44 88 21 10 441 12 2060 12 1118 0 2043 3 2055 6 43 12 44 35 41 946 -3 767 -5 869 -18 883 -14 14 -227 15 -2055 16 -1557 0 -2048 3 -2075 12 -40 14 -52 51 -69 207 -5 47 -21 139 -35 205 -14 66 -26 140 -26 165 -1 25 -7 74 -15 110 -19 85 -19 86 7 110 l22 21 2261 -1 2260 -1 17 22 c17 20 18 83 18 1030 0 996 0 1009 -20 1029 -11 11 -26 20 -32 20 -1912 3 -5983 4 -6560 2z"/></g><g transform="translate(0 1018)"><g transform="translate(0.000000,810.000000) scale(0.100000,-0.100000)"><path d="M32330 8089 c-153 -5 -292 -16 -370 -29 -69 -10 -170 -24 -225 -31 -55 -6 -143 -19 -195 -29 -52 -10 -126 -24 -165 -30 -38 -6 -108 -22 -155 -36 -47 -13 -125 -34 -175 -48 -150 -39 -236 -66 -269 -83 -17 -9 -58 -26 -91 -39 -125 -46 -198 -75 -238 -93 -23 -10 -60 -24 -81 -31 -44 -14 -159 -68 -222 -104 -23 -13 -104 -58 -180 -101 -163 -92 -422 -275 -671 -473 -84 -67 -316 -312 -366 -387 -22 -33 -58 -82 -79 -110 -115 -148 -214 -286 -241 -335 -162 -299 -287 -554 -287 -585 0 -8 -11 -45 -25 -82 -14 -37 -35 -97 -46 -133 -11 -36 -26 -79 -34 -95 -8 -17 -21 -71 -30 -120 -9 -50 -23 -114 -31 -142 -8 -29 -24 -115 -35 -190 -11 -76 -22 -145 -24 -153 -13 -43 -36 -418 -36 -570 0 -246 18 -575 34 -640 8 -30 20 -86 26 -125 11 -67 25 -132 70 -325 11 -47 30 -112 42 -145 11 -33 25 -73 29 -90 30 -106 100 -287 149 -385 170 -337 218 -423 298 -531 113 -152 179 -243 218 -302 80 -119 388 -398 686 -622 15 -11 60 -45 100 -76 76 -58 125 -88 364 -219 141 -78 224 -117 390 -185 50 -20 115 -47 145 -59 69 -30 194 -68 540 -167 202 -58 415 -101 616 -124 72 -8 164 -21 205 -29 232 -46 1123 -40 1374 9 39 7 120 18 180 25 61 6 151 20 200 30 50 10 127 24 173 30 45 6 102 18 125 26 23 8 89 26 147 39 146 34 283 72 354 99 34 13 106 40 161 60 120 45 234 96 335 149 41 22 127 67 190 100 165 85 186 98 377 225 272 180 560 438 796 712 122 142 383 524 441 645 21 44 50 103 64 130 46 92 128 289 146 355 10 36 26 85 37 110 10 25 27 79 39 120 12 41 28 98 36 125 8 28 17 77 20 110 3 33 19 117 35 187 35 154 36 169 5 205 l-24 28 -1279 0 c-703 0 -1290 -3 -1305 -7 -36 -11 -61 -55 -99 -173 -37 -116 -83 -218 -131 -291 -149 -230 -151 -231 -326 -378 -82 -68 -131 -99 -235 -150 -223 -108 -249 -119 -332 -133 -44 -8 -107 -22 -140 -32 -33 -10 -103 -24 -155 -30 -52 -7 -162 -22 -245 -34 -202 -29 -503 -28 -680 1 -66 11 -147 23 -180 27 -80 9 -270 69 -362 114 -23 12 -48 21 -55 21 -8 0 -22 6 -33 14 -11 7 -59 36 -106 63 -202 116 -346 249 -499 460 -54 75 -146 264 -165 339 -9 38 -23 80 -30 94 -7 14 -20 68 -29 120 -9 52 -24 113 -33 135 -14 33 -16 86 -16 320 0 277 0 281 32 435 18 85 39 169 46 185 7 17 21 55 30 85 26 82 118 266 178 352 66 97 230 267 327 339 198 149 519 285 742 314 59 8 129 20 155 26 126 28 578 30 793 3 72 -9 293 -55 313 -65 9 -6 57 -18 105 -29 68 -14 129 -39 270 -108 176 -88 186 -94 333 -222 113 -97 165 -151 205 -208 150 -215 163 -241 224 -422 37 -108 52 -141 74 -160 27 -21 2604 -15 2628 7 31 28 31 73 -1 213 -17 74 -31 151 -31 173 0 21 -14 82 -31 135 -41 128 -84 264 -105 332 -21 72 -61 174 -89 230 -13 25 -35 72 -50 105 -71 157 -120 253 -151 299 -94 141 -138 207 -181 271 -54 82 -78 113 -236 302 -65 79 -131 152 -145 164 -15 11 -43 35 -62 53 -391 358 -641 526 -1156 781 -130 64 -185 87 -359 150 -49 18 -108 39 -130 47 -193 71 -722 188 -940 208 -66 6 -158 18 -205 26 -170 29 -653 45 -1000 33z M16 7957 c-17 -12 -18 -22 -13 -83 9 -98 35 -283 41 -293 6 -8 31 -178 41 -276 4 -33 13 -85 20 -115 8 -30 21 -113 30 -185 8 -71 20 -152 25 -180 10 -53 40 -246 59 -380 6 -44 18 -109 26 -145 8 -36 19 -110 24 -166 6 -55 20 -142 31 -194 11 -51 25 -139 30 -194 6 -55 21 -159 34 -231 14 -71 32 -184 41 -250 8 -66 22 -151 29 -190 8 -38 19 -115 25 -170 6 -55 20 -145 31 -200 10 -55 24 -140 30 -190 7 -49 19 -124 27 -165 24 -114 27 -227 10 -296 -8 -32 -22 -108 -30 -169 -9 -60 -21 -141 -26 -180 -6 -38 -20 -142 -31 -230 -11 -88 -29 -207 -40 -265 -11 -58 -25 -150 -31 -205 -6 -55 -21 -161 -35 -235 -13 -74 -29 -184 -35 -244 -5 -60 -19 -149 -30 -198 -10 -49 -24 -140 -29 -201 -6 -62 -17 -146 -25 -187 -16 -77 -25 -142 -51 -340 -8 -63 -21 -144 -29 -180 -7 -36 -21 -128 -30 -205 -9 -77 -23 -167 -30 -200 -8 -33 -16 -91 -20 -130 -8 -88 -22 -188 -41 -285 -19 -100 -44 -295 -44 -346 0 -32 5 -43 23 -53 17 -9 731 -11 3247 -8 2950 3 3231 5 3293 20 37 9 96 19 130 22 73 7 187 31 238 50 19 7 75 23 124 34 49 12 114 32 144 45 31 13 101 43 156 66 55 23 132 60 170 82 39 22 90 52 115 66 198 111 471 365 595 552 114 174 228 412 254 531 6 25 19 66 30 92 11 25 23 67 26 94 4 26 19 95 33 153 26 102 27 113 27 410 -1 296 -1 308 -27 405 -14 55 -29 122 -33 149 -4 26 -17 74 -29 105 -13 31 -38 94 -56 141 -18 47 -42 101 -55 120 -12 20 -34 59 -48 87 -55 107 -200 271 -365 410 -87 73 -161 133 -165 133 -5 0 -23 11 -40 24 -64 48 -383 201 -490 235 -104 33 -177 68 -177 85 0 9 17 25 38 36 225 125 485 365 617 570 70 108 187 345 201 407 3 15 16 57 29 93 13 36 27 83 31 105 3 22 18 87 32 145 24 98 26 121 27 345 0 151 -5 271 -13 325 -24 152 -54 283 -76 335 -13 28 -26 68 -30 89 -9 49 -61 168 -96 221 -15 22 -47 74 -70 115 -52 90 -62 103 -197 247 -107 114 -118 122 -388 293 -99 63 -180 103 -285 142 -30 12 -64 27 -75 34 -26 16 -304 94 -336 94 -14 0 -68 9 -120 20 -201 43 -170 43 -3334 47 -2579 3 -3043 2 -3059 -10z m4917 -1792 c277 -42 436 -212 484 -517 7 -44 -21 -174 -52 -243 -30 -67 -119 -200 -147 -220 -219 -153 -151 -145 -1285 -145 -501 0 -919 3 -927 6 -9 4 -21 15 -26 25 -13 23 -40 164 -40 207 0 18 -13 97 -29 175 -16 78 -32 176 -36 217 -3 41 -15 112 -26 157 -11 44 -26 134 -34 199 -13 108 -13 119 2 136 15 17 65 18 1017 18 833 0 1017 -3 1099 -15z m515 -2888 c103 -30 206 -82 266 -137 43 -38 155 -202 160 -235 2 -11 16 -73 31 -138 23 -97 27 -132 22 -192 -8 -87 -56 -243 -94 -299 -71 -108 -194 -204 -308 -240 -27 -8 -75 -24 -105 -35 -54 -20 -81 -20 -1326 -21 -1473 0 -1315 -14 -1296 112 6 40 16 116 22 168 6 52 17 124 25 160 8 36 22 124 30 195 9 72 24 173 35 225 11 52 24 136 30 185 6 50 15 119 20 155 5 36 10 71 10 78 0 6 9 21 20 32 21 21 26 21 1193 17 l1172 -3 93 -27z M37977 7956 c-17 -13 -17 -191 -17 -3852 l-1 -3838 23 -23 23 -22 1265 -2 c885 -1 1273 2 1292 9 19 8 30 22 37 49 7 26 11 464 11 1359 l0 1321 23 21 23 22 1631 0 c1566 0 1631 -1 1646 -18 16 -17 17 -127 17 -1370 0 -1308 1 -1353 19 -1371 18 -18 50 -19 702 -22 376 -2 953 -2 1282 1 l599 5 19 24 c19 23 19 98 19 3857 l0 3833 -30 16 c-25 13 -181 15 -1303 15 -1221 0 -1275 -1 -1290 -18 -16 -17 -17 -121 -17 -1294 0 -1065 -2 -1277 -14 -1287 -10 -8 -447 -11 -1651 -11 -1517 0 -1639 1 -1657 16 -17 16 -18 64 -18 1279 0 1228 -1 1263 -19 1286 l-19 24 -1289 2 c-1069 2 -1291 0 -1306 -11z M9243 7913 c-17 -26 -18 -139 -18 -2248 0 -1588 3 -2267 12 -2385 17 -250 95 -651 154 -797 16 -39 29 -79 29 -88 0 -8 12 -43 26 -78 14 -34 32 -86 40 -115 14 -53 61 -154 124 -267 18 -33 46 -85 62 -115 140 -268 486 -678 737 -874 57 -45 121 -94 142 -111 164 -129 216 -165 359 -245 41 -24 102 -59 135 -78 77 -46 183 -97 259 -127 34 -13 111 -44 171 -70 116 -48 220 -83 346 -115 41 -10 98 -28 125 -39 28 -10 77 -24 110 -30 133 -24 373 -60 434 -66 36 -3 112 -15 170 -25 251 -45 1147 -45 1266 1 13 4 85 15 161 24 317 36 768 144 983 234 36 15 101 43 145 61 145 60 240 103 275 125 19 12 69 41 110 65 41 24 91 53 110 65 19 12 64 39 100 60 236 141 649 515 827 750 169 224 195 264 292 445 45 85 86 162 92 170 20 34 49 100 49 113 0 8 11 39 24 68 13 30 27 70 31 89 3 19 19 69 35 110 54 144 73 205 82 265 6 33 23 119 39 190 62 282 63 319 67 2750 1 1205 0 2209 -3 2233 -4 24 -15 52 -26 63 -19 19 -48 19 -1297 19 l-1279 0 -19 -21 c-19 -21 -19 -75 -25 -2168 l-5 -2146 -22 -150 c-28 -188 -32 -206 -77 -335 -148 -425 -490 -677 -1000 -736 -166 -19 -579 -24 -655 -7 -365 80 -405 99 -652 301 -98 80 -232 263 -283 386 -19 47 -35 92 -35 101 0 8 -12 43 -26 78 -22 52 -28 91 -40 242 -8 99 -19 205 -24 235 -7 36 -10 767 -10 2111 0 1131 -2 2063 -5 2071 -15 38 -25 38 -1330 38 l-1275 0 -17 -27z M18331 7886 c-16 -19 -17 -7647 -1 -7676 11 -20 21 -20 1290 -20 1270 0 1280 0 1300 20 20 20 20 27 20 2361 0 1287 2 2344 5 2349 9 15 30 1 50 -33 20 -35 90 -143 181 -282 32 -49 61 -94 63 -100 2 -5 50 -80 106 -165 56 -85 150 -233 211 -328 60 -95 125 -199 145 -230 20 -31 59 -93 86 -137 132 -212 177 -282 198 -305 12 -14 34 -47 50 -75 15 -27 45 -75 65 -105 39 -58 74 -112 210 -325 45 -71 102 -159 125 -195 23 -36 75 -117 115 -180 94 -149 150 -235 219 -340 31 -47 61 -94 67 -105 6 -11 41 -67 79 -125 37 -58 93 -143 123 -190 30 -47 62 -97 72 -111 11 -15 46 -73 80 -129 34 -56 69 -114 79 -129 10 -14 29 -44 43 -66 43 -68 110 -173 233 -360 124 -190 139 -213 238 -367 34 -54 95 -149 136 -213 65 -100 79 -116 110 -125 23 -6 610 -10 1668 -10 1470 0 1633 2 1647 16 14 14 16 172 17 1587 1 4147 -3 6082 -13 6094 -16 20 -2571 19 -2587 0 -8 -10 -12 -633 -13 -2281 -3 -1979 -5 -2268 -18 -2276 -17 -10 -40 5 -40 27 0 8 -13 32 -28 52 -16 20 -49 70 -74 111 -25 41 -88 139 -139 217 -52 78 -121 184 -153 235 -33 51 -85 131 -115 178 -31 47 -58 89 -60 95 -2 5 -16 28 -31 50 -48 71 -155 238 -205 320 -27 44 -59 94 -72 111 -13 17 -23 33 -23 36 0 3 -28 48 -62 100 -35 52 -87 132 -116 179 -313 499 -390 619 -423 664 -16 22 -44 63 -61 90 -39 63 -77 122 -164 255 -71 107 -88 134 -101 160 -7 15 -101 162 -172 270 -20 30 -40 62 -46 71 -5 10 -23 38 -40 63 -26 40 -94 146 -170 266 -55 87 -246 385 -320 500 -16 25 -34 53 -40 62 -15 24 -202 318 -259 405 -12 18 -34 37 -50 43 -49 17 -3421 14 -3435 -4z"/></g></g></svg><span class="lab">• Lab</span></a><div class="nav-links"><a class="hide-sm" href="../#news">Новости</a><a href="../sionika/">Sionika</a><a href="../aria/" aria-current="page">Aria</a><a class="tg" href="https://t.me/ByteBunch" target="_blank" rel="noopener">Telegram</a></div></div></nav>
<header class="a-hero" id="top">
  <div class="wrap">
    <div>
      <div class="crumb"><a href="../#projects">Проекты</a> / Aria</div>
      <h1>Aria <small>0.6.2</small></h1>
      <p class="what">Движок распознавания высоты звука</p>
      <p class="lede">Aria слушает струну через микрофон телефона и показывает, на сколько центов она выше или ниже ноты. Мы сравнили её с GuitarTuna и 18 открытыми алгоритмами на одних и тех же звуках. Ниже все результаты, в том числе те, где Aria не первая.</p>
      <div class="meta"><span>релиз 05.10.2026</span><span>встроена в Sionika 3.0 beta</span><span>работает на устройстве</span></div>
      <div class="acts"><a class="btn" href="#results">Результаты <span class="ar">→</span></a><a class="btn line" href="../sionika/#tuner">Тюнер в Sionika</a></div>
    </div>
    <figure class="readout" style="margin:0">
      <svg id="hdial" viewBox="0 0 560 170" role="img" aria-label="Шкала центов: Aria показывает 0, GuitarTuna +16 на точно настроенной струне E2"></svg>
      <p>Точно настроенная струна E2 с жёсткостью как у настоящей. Aria показывает 0 центов, GuitarTuna — +16: по ней такую струну ослабили бы.</p>
    </figure>
  </div>
</header>

<main>
  <section class="sec" id="summary" style="border-top:0;padding-top:24px">
    <div class="wrap">
      <div class="stats rv">
        <div class="stat"><div class="v">94<span>%</span></div><div class="l">времени стрелка точна, в пределах ±5 центов</div><div class="vs"><i class="key" style="background:var(--gt)"></i>GuitarTuna: 48 %</div></div>
        <div class="stat"><div class="v">0,24<span>с</span></div><div class="l">от щипка до точного показания</div><div class="vs"><i class="key" style="background:var(--gt)"></i>GuitarTuna: 0,62 с</div></div>
        <div class="stat"><div class="v">0<span>ц</span></div><div class="l">ошибка на басовых струнах E2 и A2</div><div class="vs"><i class="key" style="background:var(--gt)"></i>GuitarTuna: 11 ц</div></div>
      </div>
      <p class="note" style="margin-top:16px">Точность — среднее по пяти условиям: тишина, микрофон телефона, шорох бумаги, разговор рядом и шум. Толпу разбираем ниже отдельно. GuitarTuna — онлайн-версия на guitartuna.com.</p>
      <div class="cent rv"><b>1 ц</b><span>Цент — сотая доля полутона. 0 — нота точно, +10 — выше на десятую часть полутона. Ошибку в 10–15 центов обычно слышно, когда играешь аккорд.</span></div>
    </div>
  </section>

  <section class="sec" id="how">
    <div class="wrap">
      <div class="sec-head rv"><h2>Как работает</h2><p class="meta">четыре шага после щипка</p></div>
      <ol class="steps rv">
        <li><span class="n">1 / 4</span><svg viewBox="0 0 240 76" aria-hidden="true"><path id="g1" fill="none" stroke="#f4f4f4" stroke-width="1.4"/><line x1="40" x2="40" y1="6" y2="70" stroke="#4b93ea" stroke-width="1.5"/></svg><h3>Слышит щипок</h3><p>Отрезает низкий гул и высокий шорох и начинает новую ноту только после щипка. Разговор рядом и шорох почти не сбивают стрелку.</p></li>
        <li><span class="n">2 / 4</span><svg viewBox="0 0 240 76" aria-hidden="true"><g id="g2"></g></svg><h3>Разбирает гармоники</h3><p>Струна звучит набором обертонов. Aria измеряет каждый отдельно и считает основной тон с поправкой на жёсткость струны.</p></li>
        <li><span class="n">3 / 4</span><svg viewBox="0 0 240 76" aria-hidden="true"><g id="g3"></g></svg><h3>Узнаёт ноту в шуме</h3><p>Когда движок сомневается, маленькая нейросеть SwiftF0 подсказывает ноту. Без неё (версия Lite) в толпе точность падает с 27 % до 7 %.</p></li>
        <li><span class="n">4 / 4</span><svg viewBox="0 0 240 76" aria-hidden="true"><g id="g4"></g></svg><h3>Ведёт стрелку</h3><p>Сразу после щипка стрелка быстрая, потом успокаивается. На записях гитары с телефона она гуляет в пределах 3,5 центов.</p></li>
      </ol>
    </div>
  </section>

  <section class="sec" id="bass">
    <div class="wrap">
      <div class="sec-head rv"><h2>Почему тюнеры ошибаются на басах</h2><p class="meta">жёсткость струны</p></div>
      <div class="prose rv" style="margin-bottom:40px">
        <p>У идеальной струны гармоники стоят ровно на кратных частотах. Настоящая струна жёсткая, и её гармоники чуть выше: чем толще струна и чем выше номер гармоники, тем больше сдвиг. Тюнер, который читает высоту по верхним гармоникам, видит струну выше, чем она есть, и по нему её ослабляют. Aria убирает этот сдвиг и показывает основной тон.</p>
      </div>
      <div class="explore rv">
        <div class="ctrl">
          <div><span class="lab">струна</span><div class="seg" id="strSeg" role="group" aria-label="Струна"></div></div>
          <div><label for="bSlider">жёсткость струны, B = <span id="bVal">6·10⁻⁵</span></label><input type="range" id="bSlider" min="0" max="150" step="1" value="60" aria-describedby="bHelp"><p class="note" id="bHelp" style="margin-top:6px">На наших записях у струны E2 B ≈ 6·10⁻⁵.</p></div>
          <div class="rd" aria-live="polite">
            <div><b id="rdAria" style="color:var(--aria)">0,0</b><span>Aria, ц</span><small>основной тон</small></div>
            <div><b id="rdTop" style="color:var(--gt)">+8,7</b><span>по верхним гармоникам, ц</span><small id="rdTopSub">гармоника 13</small></div>
          </div>
          <p class="note">Вторая цифра — модель тюнера, который читает струну по гармонике около 1450 Гц, но не выше 13-й. Так, по нашим замерам, ведёт себя GuitarTuna.</p>
        </div>
        <figure class="fig" style="border-top:0;padding-top:0">
          <div class="fig-head"><div class="fig-title">Насколько гармоники выше идеальных, центы</div></div>
          <div class="plot" id="harm"></div>
        </figure>
      </div>
      <figure class="fig rv" style="margin-top:56px">
        <div class="fig-head"><div><div class="fig-title">Тест: что показывают тюнеры на точно настроенной струне</div><div class="fig-sub">Правильный ответ — 0 центов. Идеальная струна — без жёсткости, в жизни таких не бывает.</div></div><div class="legend" id="bassLegend"></div></div>
        <div class="plot" id="bassPlot"></div>
      </figure>
    </div>
  </section>

  <section class="sec" id="results">
    <div class="wrap">
      <div class="sec-head rv"><h2>Точность, скорость и ошибки</h2><p class="meta">108 щипков · 24 фрагмента записей · 6 условий</p></div>
      <div class="prose rv" style="margin-bottom:40px"><p>Все тюнеры слушали одни и те же звуки. Правильный ответ — основной тон струны. Для сравнения взяли лучший из 18 открытых алгоритмов, <b>aubio mcomb</b>.</p></div>
      <div class="blk">
        <figure class="fig rv">
          <div class="fig-head"><div><div class="fig-title">Как часто стрелка точна</div><div class="fig-sub">Доля времени с 0,3 до 2 секунд после щипка с точностью ±5 центов. Пауза без показания — тоже промах. Нажми на тюнер в легенде, чтобы выделить его.</div></div><div class="legend" id="accLegend"></div></div>
          <div class="plot" id="acc"></div>
        </figure>
        <p class="note rv">В толпе, где шесть голосов звучат так же громко, как струна, все тюнеры почти бесполезны, и GuitarTuna здесь немного лучше Aria.</p>
      </div>
      <div class="blk">
        <figure class="fig rv">
          <div class="fig-head"><div><div class="fig-title">Скорость: от щипка до показания</div><div class="fig-sub">Анимация в 3 раза медленнее реального времени.</div></div><div class="race-ctl"><span class="clock" id="clock">0,00 с</span><button class="replay" id="replay" type="button">Повторить</button></div></div>
          <div class="legend" id="raceLegend"></div>
          <div class="plot" id="race"></div>
        </figure>
      </div>
      <div class="blk">
        <h3 class="sub rv">Все показатели</h3>
        <div class="tbox rv"><table class="t fit" id="cmpTable"></table></div>
        <p class="note">Квадрат у числа — лучшее значение в строке.</p>
      </div>
      <div class="blk">
        <h3 class="sub rv">Где Aria пока не первая</h3>
        <ul class="cons rv">
          <li><b>Толпа</b><p>Шесть голосов той же громкости, что и струна: GuitarTuna 32,2 %, aubio mcomb 29,4 %, Aria 27,4 %.</p></li>
          <li><b>Скорость</b><p>aubio mcomb показывает точную ноту на 0,09 с раньше. Зато в шуме он показывает ноту, даже когда струна молчит: 64 % времени.</p></li>
          <li><b>Октава и тишина</b><p>GuitarTuna реже ошибается на октаву (0,1 % против 0,3 %) и не показывает ноту до щипка (0 % против 1,5 %).</p></li>
          <li><b>Плавность</b><p>На записях с телефона стрелка GuitarTuna гуляет чуть меньше: 3,2 ц против 3,5 ц.</p></li>
          <li><b>Название ноты</b><p>Нейросеть SwiftF0 сама по себе узнаёт ноту чаще (88,6 % против 86,1 %), но с точностью ±5 центов попадает только в 64 % времени.</p></li>
          <li><b>Пианино рядом</b><p>Ноты пианино иногда уводят стрелку: 12 % кадров в первую секунду после щипка.</p></li>
        </ul>
        <details class="rv"><summary>Полная таблица: все тюнеры и алгоритмы</summary><div class="tbox"><table class="t" id="rankTable"></table></div><p class="note" style="margin-top:12px">Точность — среднее по всем шести условиям, включая толпу. «Не успевает» — тюнер не дошёл до точного показания хотя бы в половине щипков.</p></details>
      </div>
    </div>
  </section>

  <section class="sec" id="new">
    <div class="wrap">
      <div class="sec-head rv"><h2>Что нового в 0.6.2</h2><p class="meta">релиз 05.10.2026</p></div>
      <div class="blk">
        <h3 class="sub rv">Стрелка больше не застревает после щипка</h3>
        <div class="prose rv"><p>В 0.6 сразу после сильного щипка стрелка иногда на полсекунды застревала ниже ноты. Мы нашли это на записях настоящей гитары и исправили. На графике доля кадров в первую секунду после щипка, когда стрелка дальше 6 центов от места, где нота потом успокаивается. Меньше — лучше.</p></div>
        <figure class="fig rv"><div class="fig-head"><div><div class="fig-title">Застревание стрелки, % кадров</div><div class="fig-sub">Справа: 0.6 → 0.6.2 · как в GuitarTuna.</div></div><div class="legend" id="slopeLegend"></div></div><div class="plot" id="slope"></div></figure>
        <p class="note">На записи с пианино 0.6.2 чуть хуже 0.6: там стрелку уводят ноты пианино, а не атака щипка. В режиме «как в GuitarTuna» стрелка плавно доходит до поправки, поэтому его цифры немного выше.</p>
      </div>
      <div class="blk" id="gt">
        <h3 class="sub rv">Режим «Строй как в GuitarTuna»</h3>
        <div class="prose rv"><p>GuitarTuna на толстых струнах показывает выше основного тона, и по ней басы настраиваются на 5–15 центов ниже. Если группа строится по GuitarTuna, а ты по Sionika, басы разойдутся. Для этого в тюнере Sionika есть переключатель <b>«Строй как в GuitarTuna»</b>. По умолчанию он выключен.</p></div>
        <figure class="fig rv"><div class="fig-head"><div><div class="fig-title">Разница с GuitarTuna на живых струнах</div><div class="fig-sub">Aria минус онлайн-тюнер GuitarTuna, центы. Ноль — тюнеры показывают одинаково.</div></div><div class="legend" id="dumbLegend"></div></div><div class="plot" id="dumb"></div></figure>
        <details class="rv"><summary>Проверочный файл с известной высотой</summary><div class="tbox"><table class="t" id="probeTable"></table></div><p class="note" style="margin-top:12px">Шесть искусственных щипков E2. Приложение GuitarTuna — показания с экрана телефона, записаны вручную.</p></details>
      </div>
    </div>
  </section>

  <section class="sec" id="real">
    <div class="wrap">
      <div class="sec-head rv"><h2>Проверка на живых струнах</h2><p class="meta">записи 1 октября 2026</p></div>
      <div class="prose rv" style="margin-bottom:36px"><p>У настоящей струны нет одного точного числа: первая и вторая гармоники расходятся на 1–5 центов из-за жёсткости и корпуса. Мы измерили обе по спектру двух секунд звука и сравнили с показаниями тюнеров в те же секунды.</p></div>
      <figure class="fig rv"><div class="fig-head"><div><div class="fig-title">Показания тюнеров и коридор между гармониками</div><div class="fig-sub">Центы от ноты. Серая полоса — от первой до второй гармоники струны.</div></div><div class="legend" id="rangeLegend"></div></div><div class="plot" id="range"></div></figure>
      <p class="note" style="margin-top:16px">Aria везде не дальше 1 цента от коридора. GuitarTuna на басах выше обеих гармоник на 5–13 центов. На G3 тюнеры близки.</p>
    </div>
  </section>

  <section class="sec" id="versions">
    <div class="wrap">
      <div class="sec-head rv"><h2>Как росла Aria</h2><p class="meta">точность ±5 ц, среднее по 6 условиям</p></div>
      <figure class="fig rv" style="border-top:0;padding-top:0"><div class="plot" id="ver"></div></figure>
      <ul class="method rv" id="verLog" style="margin-top:28px"></ul>
      <p class="note" style="margin-top:16px">На искусственных звуках 0.6.2 идёт вровень с 0.6 (82,6 % против 83,0 %). Исправление в 0.6.2 касается атаки настоящего щипка, и видно его на записях гитары.</p>
    </div>
  </section>

  <section class="sec" id="method">
    <div class="wrap">
      <div class="sec-head rv"><h2>Как мы тестировали</h2><p class="meta">методика</p></div>
      <ul class="method rv">
        <li><b>Искусственные щипки</b><span>Шесть струн от E2 до E4, по три щипка с расстройкой до ±30 центов. У струн есть жёсткость (B = 1·10⁻⁴), резонансы корпуса и щелчок медиатора. 18 щипков в шести условиях — 108 проверок.</span></li>
        <li><b>Записи гитары</b><span>Четыре записи настоящей гитары, в шести условиях это 24 фрагмента. Истинный тон измерен по спектру всей записи.</span></li>
        <li><b>Шесть условий</b><span>Тишина. Микрофон телефона: нет звука ниже 200 Гц, основной тон ослаблен в 10 раз. Шорох бумаги той же громкости, что и струна. Разговор шести голосов на 10 дБ тише. Розовый шум на 10 дБ тише. Толпа: шесть голосов той же громкости.</span></li>
        <li><b>Точность</b><span>Доля времени с 0,3 до 2 секунд после щипка, когда показание в пределах ±5 центов от истинного тона. Пауза без показания тоже считается промахом.</span></li>
        <li><b>GuitarTuna</b><span>Онлайн-тюнер на guitartuna.com в браузере Chromium, проверен 1 октября 2026 года. Звук подавался как микрофон, показания снимались 60 раз в секунду и сверены с числом, которое тюнер пишет сам, на 28 тысячах кадров. Мобильное приложение может работать иначе.</span></li>
        <li><b>Aria</b><span>Тот же звук (32 кГц), обработка каждые 16 мс. Нейросеть SwiftF0 запускается, только когда движок не уверен, с задержкой 160 мс, как на телефоне.</span></li>
        <li><b>Открытые алгоритмы</b><span>aubio fcomb, aubio mcomb, aubio schmitt, aubio specacf, aubio yin, aubio yinfft, CREPE full, CREPE tiny, FastTune CEP, FastTune HPS, FastTune NCF, FastTune PEF, FastTune YIN, PESTO, SwiftF0, Tarsos DynWavelet, Tarsos MPM, Tarsos YIN. Они выдают сырое значение каждого кадра без логики «нота после щипка», поэтому в шуме у них больше показаний до щипка и чужих нот.</span></li>
        <li><b>Записи с телефона</b><span>Три записи на Android: тишина, пианино на фоне, шорох бумаги и басовые струны, всего 16 щипков. Меряли скорость, чужие ноты и размах стрелки с 0,5 по 2,5 секунду после щипка.</span></li>
        <li><b>Застревание стрелки</b><span>Девять записей с телефона. Для каждого щипка берём место, где нота успокоилась (медиана с 1-й по 2-ю секунду), и считаем долю кадров с 0,15 до 1 секунды, когда стрелка дальше 6 центов от него.</span></li>
        <li><b>Строй «как в GuitarTuna»</b><span>Проверочный файл из шести искусственных E2 с известной высотой и жёсткостью проигран в приложение GuitarTuna и в онлайн-тюнер. Живые струны сравнивались с онлайн-тюнером на одних и тех же записях.</span></li>
        <li><b>Гармоники живой струны</b><span>Измерены по спектру двух секунд звука, с 0,5 по 2,5 секунду после щипка. Показания тюнеров взяты за те же секунды.</span></li>
      </ul>
    </div>
  </section>
</main>
<footer class="foot"><div class="wrap"><div class="foot-grid"><div class="foot-mark"><svg aria-hidden="true" focusable="false" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 4659 1828" fill="currentColor"><g transform="translate(0.000000,779.000000) scale(0.100000,-0.100000)"><path d="M31 7779 l-34 -10 6 -67 c9 -106 26 -231 41 -302 8 -36 22 -123 31 -195 9 -71 22 -157 30 -190 7 -33 21 -118 29 -190 9 -71 21 -155 27 -185 26 -138 49 -288 49 -318 0 -18 11 -88 25 -155 14 -67 30 -167 35 -222 5 -55 18 -138 29 -185 12 -47 25 -133 31 -192 6 -58 21 -161 35 -227 14 -66 25 -137 25 -158 0 -21 9 -87 20 -148 32 -181 38 -216 49 -315 6 -52 20 -140 31 -195 10 -55 24 -149 30 -210 6 -60 20 -147 31 -193 24 -105 24 -164 0 -283 -10 -52 -24 -141 -31 -199 -6 -58 -20 -150 -30 -205 -10 -55 -24 -147 -30 -205 -6 -58 -20 -150 -30 -205 -11 -55 -24 -145 -30 -200 -6 -55 -22 -161 -35 -235 -14 -74 -30 -187 -36 -250 -6 -63 -19 -153 -30 -199 -11 -46 -24 -134 -29 -195 -6 -61 -16 -142 -24 -181 -19 -98 -33 -189 -52 -345 -9 -74 -21 -145 -25 -158 -5 -13 -16 -83 -24 -155 -8 -73 -21 -170 -30 -217 -15 -84 -26 -157 -46 -320 -6 -47 -17 -116 -25 -155 -19 -92 -44 -289 -44 -342 0 -32 5 -45 20 -53 14 -8 961 -10 3253 -7 2997 3 3237 4 3307 20 87 20 164 34 235 43 28 3 77 15 110 26 33 11 87 26 120 33 57 12 210 69 355 132 310 134 657 416 827 671 122 184 235 418 261 539 6 27 20 69 31 95 11 25 23 68 26 95 4 26 19 95 33 153 26 102 27 114 27 405 0 291 -1 303 -27 410 -15 61 -30 130 -34 155 -3 25 -16 68 -29 95 -12 28 -25 63 -29 80 -19 77 -124 276 -189 359 -174 223 -362 392 -577 520 -69 41 -374 181 -415 191 -55 13 -173 66 -179 81 -4 10 14 26 55 52 140 85 253 171 354 269 133 128 277 329 350 486 75 163 85 186 93 223 5 22 19 62 30 89 11 28 23 70 27 95 4 25 19 92 32 150 61 253 30 771 -61 1003 -13 34 -28 78 -31 97 -23 110 -195 397 -318 529 -154 166 -182 186 -517 391 -45 28 -163 81 -210 95 -14 4 -47 19 -75 33 -59 29 -298 92 -353 92 -21 0 -69 9 -107 19 -159 44 -169 44 -3325 47 -1964 2 -3017 -1 -3039 -7z m4970 -1805 c121 -33 246 -118 309 -211 46 -67 55 -89 85 -207 l29 -109 -18 -85 c-22 -110 -95 -259 -161 -332 -30 -33 -145 -106 -201 -128 -98 -39 -174 -42 -1119 -42 -855 0 -914 1 -934 18 -21 17 -50 144 -51 220 0 18 -13 97 -29 175 -16 78 -32 176 -36 217 -3 41 -15 112 -26 157 -11 44 -26 134 -34 199 -13 109 -13 119 2 136 16 18 56 19 1062 15 1013 -3 1048 -4 1122 -23z m439 -2878 c116 -32 204 -76 274 -137 60 -53 158 -210 167 -269 3 -25 17 -83 29 -130 53 -203 -21 -438 -187 -592 -62 -57 -105 -80 -249 -129 l-112 -39 -1269 0 c-1058 0 -1272 2 -1288 14 -17 12 -18 19 -7 97 7 46 17 125 22 174 6 50 17 119 25 155 8 36 22 124 30 195 9 72 24 173 35 225 11 52 24 136 30 185 6 50 15 119 20 155 5 36 10 68 10 71 0 4 9 18 21 33 l21 26 1161 -2 1162 -3 105 -29z M16610 7760 c-691 -4 -930 -8 -946 -17 -27 -16 -87 -87 -220 -263 -61 -80 -133 -174 -160 -208 -27 -35 -58 -76 -69 -92 -11 -15 -36 -47 -55 -72 -19 -25 -84 -109 -145 -188 -60 -79 -126 -163 -145 -188 -19 -24 -57 -75 -85 -112 -27 -37 -79 -104 -115 -150 -36 -46 -90 -115 -120 -155 -118 -154 -277 -360 -307 -399 -18 -23 -41 -52 -50 -66 -10 -14 -70 -92 -134 -175 -63 -82 -162 -211 -219 -285 -124 -162 -158 -200 -181 -200 -15 0 -172 183 -229 266 -8 12 -49 66 -90 120 -41 53 -110 142 -153 198 -197 257 -237 307 -407 527 -74 96 -161 210 -195 254 -33 44 -94 123 -135 175 -128 162 -178 228 -216 285 -15 22 -44 60 -64 85 -20 25 -76 97 -125 160 -49 63 -112 142 -140 176 -27 33 -90 113 -139 177 -56 74 -101 122 -121 131 -51 24 -2825 24 -2849 0 -31 -30 -21 -53 80 -179 30 -38 86 -110 124 -160 38 -49 107 -139 155 -200 215 -276 254 -325 314 -405 35 -47 89 -116 120 -155 31 -38 82 -104 114 -145 31 -41 86 -113 123 -160 36 -47 80 -104 97 -126 18 -23 77 -99 132 -169 55 -70 111 -143 125 -161 14 -19 64 -85 112 -146 49 -62 133 -171 189 -243 55 -71 115 -148 132 -171 18 -23 73 -95 124 -160 104 -135 278 -359 326 -420 18 -22 67 -87 110 -145 102 -137 203 -267 307 -396 47 -59 103 -129 124 -157 22 -28 90 -117 152 -197 62 -80 128 -167 146 -192 33 -46 190 -250 258 -333 98 -120 161 -206 170 -229 6 -17 10 -556 10 -1528 0 -1191 3 -1507 13 -1527 l13 -25 1282 -3 c1130 -2 1286 0 1312 13 l30 15 2 1528 3 1527 28 43 c15 24 88 121 161 215 198 255 252 325 304 395 26 35 85 111 132 170 47 59 105 134 130 167 25 33 74 97 110 142 36 45 87 110 114 145 28 34 73 92 101 128 29 36 66 85 83 110 43 60 66 91 133 175 30 38 97 124 147 190 51 66 108 140 128 165 19 25 97 125 172 224 75 98 167 217 204 265 37 47 81 104 97 126 17 22 71 92 120 155 50 63 104 133 120 155 50 65 166 213 254 323 45 57 93 118 107 136 14 18 45 60 70 92 25 33 54 71 65 86 23 31 217 284 255 333 14 18 68 88 120 156 52 68 121 157 153 199 32 41 76 98 97 125 117 151 130 171 130 197 0 16 -7 31 -17 36 -25 13 -821 18 -1903 12z M20927 7721 c-2260 -6 -2299 -6 -2313 -25 -12 -17 -14 -191 -14 -1156 l0 -1137 21 -19 c20 -18 62 -19 1383 -24 l1361 -5 5 -2656 c4 -2231 7 -2659 19 -2672 21 -27 2575 -25 2608 1 l23 17 1 2638 c1 2583 1 2639 20 2658 19 19 54 19 1378 19 l1358 0 27 21 26 20 0 1140 c0 1126 0 1139 -20 1159 -20 20 -33 20 -1578 20 -857 0 -1660 1 -1783 3 -123 2 -1258 1 -2522 -2z M28108 7722 c-790 -2 -797 -2 -808 -22 -13 -24 -6 -132 15 -235 25 -121 45 -253 45 -303 0 -26 5 -65 10 -87 19 -81 50 -278 50 -323 0 -26 7 -78 15 -116 21 -101 43 -241 49 -321 3 -38 17 -124 30 -190 13 -66 27 -158 30 -205 4 -47 17 -141 30 -210 14 -69 29 -175 35 -236 5 -61 19 -153 30 -205 11 -52 27 -161 36 -242 9 -81 20 -159 25 -173 5 -13 16 -86 25 -162 8 -75 17 -157 20 -182 3 -25 11 -76 19 -115 22 -106 46 -266 46 -306 0 -19 7 -60 16 -90 21 -70 21 -174 1 -269 -9 -41 -23 -138 -32 -215 -9 -77 -22 -167 -30 -200 -8 -33 -21 -125 -30 -205 -9 -80 -23 -173 -31 -208 -8 -34 -21 -126 -30 -205 -8 -78 -24 -184 -35 -235 -11 -51 -24 -141 -30 -200 -5 -59 -21 -165 -34 -237 -14 -71 -29 -178 -35 -237 -5 -59 -18 -150 -30 -203 -11 -54 -24 -143 -30 -199 -9 -100 -18 -156 -46 -293 -7 -40 -14 -93 -14 -119 0 -49 -8 -100 -41 -262 -10 -51 -19 -112 -19 -137 0 -47 -22 -199 -45 -305 -24 -114 -29 -225 -10 -244 15 -14 91 -16 828 -16 6432 -1 6600 0 6623 15 13 8 26 29 29 47 3 18 5 477 3 1020 -3 932 -4 988 -21 1005 -17 17 -125 18 -2309 20 -2039 3 -2294 4 -2315 18 -27 18 -27 29 -8 110 8 33 15 81 15 107 0 47 6 82 41 240 10 48 19 106 19 130 1 24 9 77 18 118 15 63 22 77 44 88 21 10 441 12 2060 12 1118 0 2043 3 2055 6 43 12 44 35 41 946 -3 767 -5 869 -18 883 -14 14 -227 15 -2055 16 -1557 0 -2048 3 -2075 12 -40 14 -52 51 -69 207 -5 47 -21 139 -35 205 -14 66 -26 140 -26 165 -1 25 -7 74 -15 110 -19 85 -19 86 7 110 l22 21 2261 -1 2260 -1 17 22 c17 20 18 83 18 1030 0 996 0 1009 -20 1029 -11 11 -26 20 -32 20 -1912 3 -5983 4 -6560 2z"/></g><g transform="translate(0 1018)"><g transform="translate(0.000000,810.000000) scale(0.100000,-0.100000)"><path d="M32330 8089 c-153 -5 -292 -16 -370 -29 -69 -10 -170 -24 -225 -31 -55 -6 -143 -19 -195 -29 -52 -10 -126 -24 -165 -30 -38 -6 -108 -22 -155 -36 -47 -13 -125 -34 -175 -48 -150 -39 -236 -66 -269 -83 -17 -9 -58 -26 -91 -39 -125 -46 -198 -75 -238 -93 -23 -10 -60 -24 -81 -31 -44 -14 -159 -68 -222 -104 -23 -13 -104 -58 -180 -101 -163 -92 -422 -275 -671 -473 -84 -67 -316 -312 -366 -387 -22 -33 -58 -82 -79 -110 -115 -148 -214 -286 -241 -335 -162 -299 -287 -554 -287 -585 0 -8 -11 -45 -25 -82 -14 -37 -35 -97 -46 -133 -11 -36 -26 -79 -34 -95 -8 -17 -21 -71 -30 -120 -9 -50 -23 -114 -31 -142 -8 -29 -24 -115 -35 -190 -11 -76 -22 -145 -24 -153 -13 -43 -36 -418 -36 -570 0 -246 18 -575 34 -640 8 -30 20 -86 26 -125 11 -67 25 -132 70 -325 11 -47 30 -112 42 -145 11 -33 25 -73 29 -90 30 -106 100 -287 149 -385 170 -337 218 -423 298 -531 113 -152 179 -243 218 -302 80 -119 388 -398 686 -622 15 -11 60 -45 100 -76 76 -58 125 -88 364 -219 141 -78 224 -117 390 -185 50 -20 115 -47 145 -59 69 -30 194 -68 540 -167 202 -58 415 -101 616 -124 72 -8 164 -21 205 -29 232 -46 1123 -40 1374 9 39 7 120 18 180 25 61 6 151 20 200 30 50 10 127 24 173 30 45 6 102 18 125 26 23 8 89 26 147 39 146 34 283 72 354 99 34 13 106 40 161 60 120 45 234 96 335 149 41 22 127 67 190 100 165 85 186 98 377 225 272 180 560 438 796 712 122 142 383 524 441 645 21 44 50 103 64 130 46 92 128 289 146 355 10 36 26 85 37 110 10 25 27 79 39 120 12 41 28 98 36 125 8 28 17 77 20 110 3 33 19 117 35 187 35 154 36 169 5 205 l-24 28 -1279 0 c-703 0 -1290 -3 -1305 -7 -36 -11 -61 -55 -99 -173 -37 -116 -83 -218 -131 -291 -149 -230 -151 -231 -326 -378 -82 -68 -131 -99 -235 -150 -223 -108 -249 -119 -332 -133 -44 -8 -107 -22 -140 -32 -33 -10 -103 -24 -155 -30 -52 -7 -162 -22 -245 -34 -202 -29 -503 -28 -680 1 -66 11 -147 23 -180 27 -80 9 -270 69 -362 114 -23 12 -48 21 -55 21 -8 0 -22 6 -33 14 -11 7 -59 36 -106 63 -202 116 -346 249 -499 460 -54 75 -146 264 -165 339 -9 38 -23 80 -30 94 -7 14 -20 68 -29 120 -9 52 -24 113 -33 135 -14 33 -16 86 -16 320 0 277 0 281 32 435 18 85 39 169 46 185 7 17 21 55 30 85 26 82 118 266 178 352 66 97 230 267 327 339 198 149 519 285 742 314 59 8 129 20 155 26 126 28 578 30 793 3 72 -9 293 -55 313 -65 9 -6 57 -18 105 -29 68 -14 129 -39 270 -108 176 -88 186 -94 333 -222 113 -97 165 -151 205 -208 150 -215 163 -241 224 -422 37 -108 52 -141 74 -160 27 -21 2604 -15 2628 7 31 28 31 73 -1 213 -17 74 -31 151 -31 173 0 21 -14 82 -31 135 -41 128 -84 264 -105 332 -21 72 -61 174 -89 230 -13 25 -35 72 -50 105 -71 157 -120 253 -151 299 -94 141 -138 207 -181 271 -54 82 -78 113 -236 302 -65 79 -131 152 -145 164 -15 11 -43 35 -62 53 -391 358 -641 526 -1156 781 -130 64 -185 87 -359 150 -49 18 -108 39 -130 47 -193 71 -722 188 -940 208 -66 6 -158 18 -205 26 -170 29 -653 45 -1000 33z M16 7957 c-17 -12 -18 -22 -13 -83 9 -98 35 -283 41 -293 6 -8 31 -178 41 -276 4 -33 13 -85 20 -115 8 -30 21 -113 30 -185 8 -71 20 -152 25 -180 10 -53 40 -246 59 -380 6 -44 18 -109 26 -145 8 -36 19 -110 24 -166 6 -55 20 -142 31 -194 11 -51 25 -139 30 -194 6 -55 21 -159 34 -231 14 -71 32 -184 41 -250 8 -66 22 -151 29 -190 8 -38 19 -115 25 -170 6 -55 20 -145 31 -200 10 -55 24 -140 30 -190 7 -49 19 -124 27 -165 24 -114 27 -227 10 -296 -8 -32 -22 -108 -30 -169 -9 -60 -21 -141 -26 -180 -6 -38 -20 -142 -31 -230 -11 -88 -29 -207 -40 -265 -11 -58 -25 -150 -31 -205 -6 -55 -21 -161 -35 -235 -13 -74 -29 -184 -35 -244 -5 -60 -19 -149 -30 -198 -10 -49 -24 -140 -29 -201 -6 -62 -17 -146 -25 -187 -16 -77 -25 -142 -51 -340 -8 -63 -21 -144 -29 -180 -7 -36 -21 -128 -30 -205 -9 -77 -23 -167 -30 -200 -8 -33 -16 -91 -20 -130 -8 -88 -22 -188 -41 -285 -19 -100 -44 -295 -44 -346 0 -32 5 -43 23 -53 17 -9 731 -11 3247 -8 2950 3 3231 5 3293 20 37 9 96 19 130 22 73 7 187 31 238 50 19 7 75 23 124 34 49 12 114 32 144 45 31 13 101 43 156 66 55 23 132 60 170 82 39 22 90 52 115 66 198 111 471 365 595 552 114 174 228 412 254 531 6 25 19 66 30 92 11 25 23 67 26 94 4 26 19 95 33 153 26 102 27 113 27 410 -1 296 -1 308 -27 405 -14 55 -29 122 -33 149 -4 26 -17 74 -29 105 -13 31 -38 94 -56 141 -18 47 -42 101 -55 120 -12 20 -34 59 -48 87 -55 107 -200 271 -365 410 -87 73 -161 133 -165 133 -5 0 -23 11 -40 24 -64 48 -383 201 -490 235 -104 33 -177 68 -177 85 0 9 17 25 38 36 225 125 485 365 617 570 70 108 187 345 201 407 3 15 16 57 29 93 13 36 27 83 31 105 3 22 18 87 32 145 24 98 26 121 27 345 0 151 -5 271 -13 325 -24 152 -54 283 -76 335 -13 28 -26 68 -30 89 -9 49 -61 168 -96 221 -15 22 -47 74 -70 115 -52 90 -62 103 -197 247 -107 114 -118 122 -388 293 -99 63 -180 103 -285 142 -30 12 -64 27 -75 34 -26 16 -304 94 -336 94 -14 0 -68 9 -120 20 -201 43 -170 43 -3334 47 -2579 3 -3043 2 -3059 -10z m4917 -1792 c277 -42 436 -212 484 -517 7 -44 -21 -174 -52 -243 -30 -67 -119 -200 -147 -220 -219 -153 -151 -145 -1285 -145 -501 0 -919 3 -927 6 -9 4 -21 15 -26 25 -13 23 -40 164 -40 207 0 18 -13 97 -29 175 -16 78 -32 176 -36 217 -3 41 -15 112 -26 157 -11 44 -26 134 -34 199 -13 108 -13 119 2 136 15 17 65 18 1017 18 833 0 1017 -3 1099 -15z m515 -2888 c103 -30 206 -82 266 -137 43 -38 155 -202 160 -235 2 -11 16 -73 31 -138 23 -97 27 -132 22 -192 -8 -87 -56 -243 -94 -299 -71 -108 -194 -204 -308 -240 -27 -8 -75 -24 -105 -35 -54 -20 -81 -20 -1326 -21 -1473 0 -1315 -14 -1296 112 6 40 16 116 22 168 6 52 17 124 25 160 8 36 22 124 30 195 9 72 24 173 35 225 11 52 24 136 30 185 6 50 15 119 20 155 5 36 10 71 10 78 0 6 9 21 20 32 21 21 26 21 1193 17 l1172 -3 93 -27z M37977 7956 c-17 -13 -17 -191 -17 -3852 l-1 -3838 23 -23 23 -22 1265 -2 c885 -1 1273 2 1292 9 19 8 30 22 37 49 7 26 11 464 11 1359 l0 1321 23 21 23 22 1631 0 c1566 0 1631 -1 1646 -18 16 -17 17 -127 17 -1370 0 -1308 1 -1353 19 -1371 18 -18 50 -19 702 -22 376 -2 953 -2 1282 1 l599 5 19 24 c19 23 19 98 19 3857 l0 3833 -30 16 c-25 13 -181 15 -1303 15 -1221 0 -1275 -1 -1290 -18 -16 -17 -17 -121 -17 -1294 0 -1065 -2 -1277 -14 -1287 -10 -8 -447 -11 -1651 -11 -1517 0 -1639 1 -1657 16 -17 16 -18 64 -18 1279 0 1228 -1 1263 -19 1286 l-19 24 -1289 2 c-1069 2 -1291 0 -1306 -11z M9243 7913 c-17 -26 -18 -139 -18 -2248 0 -1588 3 -2267 12 -2385 17 -250 95 -651 154 -797 16 -39 29 -79 29 -88 0 -8 12 -43 26 -78 14 -34 32 -86 40 -115 14 -53 61 -154 124 -267 18 -33 46 -85 62 -115 140 -268 486 -678 737 -874 57 -45 121 -94 142 -111 164 -129 216 -165 359 -245 41 -24 102 -59 135 -78 77 -46 183 -97 259 -127 34 -13 111 -44 171 -70 116 -48 220 -83 346 -115 41 -10 98 -28 125 -39 28 -10 77 -24 110 -30 133 -24 373 -60 434 -66 36 -3 112 -15 170 -25 251 -45 1147 -45 1266 1 13 4 85 15 161 24 317 36 768 144 983 234 36 15 101 43 145 61 145 60 240 103 275 125 19 12 69 41 110 65 41 24 91 53 110 65 19 12 64 39 100 60 236 141 649 515 827 750 169 224 195 264 292 445 45 85 86 162 92 170 20 34 49 100 49 113 0 8 11 39 24 68 13 30 27 70 31 89 3 19 19 69 35 110 54 144 73 205 82 265 6 33 23 119 39 190 62 282 63 319 67 2750 1 1205 0 2209 -3 2233 -4 24 -15 52 -26 63 -19 19 -48 19 -1297 19 l-1279 0 -19 -21 c-19 -21 -19 -75 -25 -2168 l-5 -2146 -22 -150 c-28 -188 -32 -206 -77 -335 -148 -425 -490 -677 -1000 -736 -166 -19 -579 -24 -655 -7 -365 80 -405 99 -652 301 -98 80 -232 263 -283 386 -19 47 -35 92 -35 101 0 8 -12 43 -26 78 -22 52 -28 91 -40 242 -8 99 -19 205 -24 235 -7 36 -10 767 -10 2111 0 1131 -2 2063 -5 2071 -15 38 -25 38 -1330 38 l-1275 0 -17 -27z M18331 7886 c-16 -19 -17 -7647 -1 -7676 11 -20 21 -20 1290 -20 1270 0 1280 0 1300 20 20 20 20 27 20 2361 0 1287 2 2344 5 2349 9 15 30 1 50 -33 20 -35 90 -143 181 -282 32 -49 61 -94 63 -100 2 -5 50 -80 106 -165 56 -85 150 -233 211 -328 60 -95 125 -199 145 -230 20 -31 59 -93 86 -137 132 -212 177 -282 198 -305 12 -14 34 -47 50 -75 15 -27 45 -75 65 -105 39 -58 74 -112 210 -325 45 -71 102 -159 125 -195 23 -36 75 -117 115 -180 94 -149 150 -235 219 -340 31 -47 61 -94 67 -105 6 -11 41 -67 79 -125 37 -58 93 -143 123 -190 30 -47 62 -97 72 -111 11 -15 46 -73 80 -129 34 -56 69 -114 79 -129 10 -14 29 -44 43 -66 43 -68 110 -173 233 -360 124 -190 139 -213 238 -367 34 -54 95 -149 136 -213 65 -100 79 -116 110 -125 23 -6 610 -10 1668 -10 1470 0 1633 2 1647 16 14 14 16 172 17 1587 1 4147 -3 6082 -13 6094 -16 20 -2571 19 -2587 0 -8 -10 -12 -633 -13 -2281 -3 -1979 -5 -2268 -18 -2276 -17 -10 -40 5 -40 27 0 8 -13 32 -28 52 -16 20 -49 70 -74 111 -25 41 -88 139 -139 217 -52 78 -121 184 -153 235 -33 51 -85 131 -115 178 -31 47 -58 89 -60 95 -2 5 -16 28 -31 50 -48 71 -155 238 -205 320 -27 44 -59 94 -72 111 -13 17 -23 33 -23 36 0 3 -28 48 -62 100 -35 52 -87 132 -116 179 -313 499 -390 619 -423 664 -16 22 -44 63 -61 90 -39 63 -77 122 -164 255 -71 107 -88 134 -101 160 -7 15 -101 162 -172 270 -20 30 -40 62 -46 71 -5 10 -23 38 -40 63 -26 40 -94 146 -170 266 -55 87 -246 385 -320 500 -16 25 -34 53 -40 62 -15 24 -202 318 -259 405 -12 18 -34 37 -50 43 -49 17 -3421 14 -3435 -4z"/></g></g></svg><p>ByteBunch • Lab. Разработка ИИ-моделей и софта.</p></div><div><h3>Проекты</h3><ul><li><a href="../sionika/">Sionika</a></li><li><a href="../aria/">Aria</a></li></ul></div><div><h3>Лаборатория</h3><ul><li><a href="../#news">Новости</a></li><li><a href="../#about">О лаборатории</a></li></ul></div><div><h3>Связь</h3><ul><li><a href="https://t.me/ByteBunch" target="_blank" rel="noopener">Telegram</a></li><li>GitHub<span class="soon">скоро</span></li></ul></div></div><div class="foot-legal"><span>© 2026 ByteBunch • Lab</span><span>GuitarTuna — товарный знак Yousician Ltd. Мы не связаны с Yousician.</span></div></div></footer>
<div class="tip" id="tip" hidden></div>
<script src="../assets/lab.js"></script>
<script>
const P = {"ruler": {"gt": 16}, "harm": [[1, 0.0], [2, 0.156], [3, 0.415], [4, 0.779], [5, 1.246], [6, 1.816], [7, 2.489], [8, 3.266], [9, 4.145], [10, 5.126], [11, 6.21], [12, 7.395], [13, 8.681]], "bass": [{"name": "E2 · настоящая струна", "real": true, "aria": -0.01, "gt": 16.0, "oss": 0.16}, {"name": "A2 · настоящая струна", "real": true, "aria": 0.0, "gt": 6.5, "oss": 0.1}, {"name": "E2 · идеальная струна", "real": false, "aria": -0.01, "gt": 2.0, "oss": 0.01}, {"name": "A2 · идеальная струна", "real": false, "aria": 0.0, "gt": 0.5, "oss": 0.0}], "conds": [{"name": "Тишина", "sub": "чистый звук", "aria": 98.3, "gt": 41.9, "oss": 94.2}, {"name": "Микрофон телефона", "sub": "нет низких частот, основной тон тихий", "aria": 95.8, "gt": 41.8, "oss": 86.6}, {"name": "Шорох бумаги", "sub": "той же громкости, что и струна", "aria": 94.3, "gt": 54.4, "oss": 92.3}, {"name": "Разговор рядом", "sub": "шесть голосов на 10 дБ тише струны", "aria": 88.0, "gt": 48.2, "oss": 61.9}, {"name": "Шум", "sub": "розовый шум на 10 дБ тише струны", "aria": 91.8, "gt": 52.1, "oss": 71.7}, {"name": "Толпа", "sub": "шесть голосов той же громкости", "aria": 27.4, "gt": 32.2, "oss": 29.4}], "cmp": [{"name": "Точность ±5 ц", "note": "среднее по шести условиям, включая толпу", "vals": [82.6, 45.1, 72.7], "unit": " %", "better": "hi"}, {"name": "Точное показание через", "note": "от щипка до точности ±10 ц, медиана", "vals": [0.24, 0.62, 0.15], "unit": " с", "dp": 2, "better": "lo"}, {"name": "Нота появляется через", "note": "записи гитары с телефона", "vals": [0.1, 0.69, 0.08], "unit": " с", "dp": 2, "better": "lo"}, {"name": "Узнаёт ноту", "note": "показание в пределах ±50 ц, то есть нота названа верно", "vals": [86.1, 74.5, 86.5], "unit": " %", "better": "hi"}, {"name": "Ошибается на октаву", "note": "доля показаний", "vals": [0.3, 0.1, 4.8], "unit": " %", "better": "lo"}, {"name": "Показывает ноту, когда струна молчит", "note": "в шуме, до щипка", "vals": [1.5, 0.0, 63.6], "unit": " %", "better": "lo"}, {"name": "Показывает чужую ноту", "note": "записи гитары с телефона", "vals": [0.3, 25.2, 16.1], "unit": " %", "better": "lo"}, {"name": "Размах стрелки", "note": "записи с телефона, 0,5–2,5 с после щипка", "vals": [3.5, 3.2, 10.0], "unit": " ц", "better": "lo"}], "rank": [{"name": "Aria 0.6.2", "note": "с нейросетью", "mine": true, "vals": ["82,6 %", "86,1 %", "0,24 с"], "unit": "", "dp": 1}, {"name": "Aria 0.6.2 Lite", "note": "без нейросети", "mine": true, "vals": ["78,7 %", "81,4 %", "0,24 с"], "unit": "", "dp": 1}, {"name": "aubio mcomb", "note": "", "mine": false, "vals": ["72,7 %", "86,5 %", "0,15 с"], "unit": "", "dp": 1}, {"name": "aubio fcomb", "note": "", "mine": false, "vals": ["70,1 %", "80,9 %", "0,19 с"], "unit": "", "dp": 1}, {"name": "SwiftF0", "note": "нейросеть", "mine": false, "vals": ["63,9 %", "88,6 %", "0,22 с"], "unit": "", "dp": 1}, {"name": "CREPE full", "note": "нейросеть", "mine": false, "vals": ["54,4 %", "80,4 %", "0,12 с"], "unit": "", "dp": 1}, {"name": "CREPE tiny", "note": "нейросеть", "mine": false, "vals": ["53,6 %", "79,1 %", "0,15 с"], "unit": "", "dp": 1}, {"name": "GuitarTuna", "note": "онлайн-версия", "mine": false, "vals": ["45,1 %", "74,5 %", "0,62 с"], "unit": "", "dp": 1}, {"name": "aubio yin", "note": "", "mine": false, "vals": ["43,3 %", "55,9 %", "0,29 с"], "unit": "", "dp": 1}, {"name": "Tarsos YIN", "note": "", "mine": false, "vals": ["42,8 %", "52,5 %", "0,27 с"], "unit": "", "dp": 1}, {"name": "aubio yinfft", "note": "", "mine": false, "vals": ["42,0 %", "67,8 %", "0,27 с"], "unit": "", "dp": 1}, {"name": "FastTune YIN", "note": "", "mine": false, "vals": ["37,3 %", "41,2 %", "0,32 с"], "unit": "", "dp": 1}, {"name": "Tarsos MPM", "note": "", "mine": false, "vals": ["37,3 %", "41,1 %", "0,35 с"], "unit": "", "dp": 1}, {"name": "FastTune NCF", "note": "", "mine": false, "vals": ["35,2 %", "71,2 %", "0,29 с"], "unit": "", "dp": 1}, {"name": "aubio schmitt", "note": "", "mine": false, "vals": ["21,9 %", "26,1 %", "не успевает"], "unit": "", "dp": 1}, {"name": "Tarsos DynWavelet", "note": "", "mine": false, "vals": ["20,9 %", "30,2 %", "не успевает"], "unit": "", "dp": 1}, {"name": "PESTO", "note": "нейросеть", "mine": false, "vals": ["14,9 %", "65,5 %", "не успевает"], "unit": "", "dp": 1}, {"name": "aubio specacf", "note": "", "mine": false, "vals": ["9,5 %", "19,5 %", "не успевает"], "unit": "", "dp": 1}, {"name": "FastTune CEP", "note": "", "mine": false, "vals": ["9,5 %", "39,0 %", "не успевает"], "unit": "", "dp": 1}, {"name": "FastTune HPS", "note": "", "mine": false, "vals": ["5,0 %", "79,0 %", "0,10 с"], "unit": "", "dp": 1}, {"name": "FastTune PEF", "note": "", "mine": false, "vals": ["2,6 %", "81,9 %", "не успевает"], "unit": "", "dp": 1}], "stuck": [{"name": "Все 9 записей", "note": "среднее", "vals": [7.5, 3.8, 4.3], "unit": " %", "better": "lo"}, {"name": "Серия щипков G3", "note": "8 щипков подряд", "vals": [29.5, 0.0, 0.0], "unit": " %", "better": "lo"}, {"name": "Пианино на фоне", "note": "запись с телефона", "vals": [11.7, 12.3, 15.2], "unit": " %", "better": "lo"}, {"name": "Aria Lite, без нейросети", "note": "среднее по 9 записям", "vals": [7.8, 4.2, 4.3], "unit": " %", "better": "lo"}], "live": [{"name": "E2", "note": "8 щипков", "vals": [-11.4, 2.3], "signed": true, "unit": " ц", "better": "zero"}, {"name": "A2", "note": "3 щипка", "vals": [-7.4, 0.8], "signed": true, "unit": " ц", "better": "zero"}, {"name": "D3", "note": "2 щипка", "vals": [-2.9, -0.4], "signed": true, "unit": " ц", "better": "zero"}, {"name": "G3", "note": "10 щипков", "vals": [-0.7, -0.7], "signed": true, "unit": " ц", "better": "zero"}, {"name": "B3", "note": "2 щипка", "vals": [-4.3, -3.6], "signed": true, "unit": " ц", "better": "zero"}, {"name": "E4", "note": "3 щипка", "vals": [-0.5, -0.2], "signed": true, "unit": " ц", "better": "zero"}], "probe": [{"name": "Идеальная струна", "note": "ровно на ноте", "vals": [0, 0.5, 2, 0.0, 0.0], "signed": true, "unit": " ц"}, {"name": "Идеальная струна", "note": "на 5 ц ниже", "vals": [-5, -4, -4, -5.0, -5.0], "signed": true, "unit": " ц"}, {"name": "Идеальная струна", "note": "на 5 ц выше", "vals": [5, 4, 4, 5.0, 5.0], "signed": true, "unit": " ц"}, {"name": "Обычная E2", "note": "жёсткость как на наших записях, ровно на ноте", "vals": [0, 9, 8, -0.1, 8.3], "signed": true, "unit": " ц"}, {"name": "Обычная E2", "note": "на 4 ц ниже", "vals": [-4, 5, 5, -4.1, 4.7], "signed": true, "unit": " ц"}, {"name": "Жёсткая струна", "note": "жёсткость вдвое больше, ровно на ноте", "vals": [0, 17, 14, -0.1, 16.8], "signed": true, "unit": " ц"}], "real": [{"cat": "Басовые струны"}, {"name": "E2, запись 1", "note": "1 щипок", "vals": [1.5, -1.1, -1.2, 12.0], "signed": true, "unit": " ц"}, {"name": "E2, запись 2", "note": "щипок 1", "vals": [3.0, 1.1, 1.6, 16.0], "signed": true, "unit": " ц"}, {"name": "E2, запись 2", "note": "щипок 2", "vals": [4.4, 1.4, 1.1, 13.0], "signed": true, "unit": " ц"}, {"name": "A2", "note": "1 щипок", "vals": [3.3, 2.7, 2.5, 8.0], "signed": true, "unit": " ц"}, {"cat": "Средняя струна"}, {"name": "G3", "note": "серия из 8 щипков, среднее", "vals": [5.3, 1.0, 2.1, 2.8], "signed": true, "unit": " ц"}, {"cat": "E2, настроенная по приложению GuitarTuna"}, {"name": "E2", "note": "щипок 1", "vals": [-3.0, -8.2, -2.6, 7.0], "signed": true, "unit": " ц"}, {"name": "E2", "note": "щипок 2", "vals": [-3.0, -7.2, -2.2, 4.0], "signed": true, "unit": " ц"}], "versions": [{"v": "0.3", "acc": 68.3, "what": "Фильтры от гула и шороха, новая нота только после щипка."}, {"v": "0.4", "acc": 70.8, "what": "Музыка и пианино на фоне: тюнер слушает только около струн гитары, держит ноту, пока она звучит, а нейросеть подтверждает ноту."}, {"v": "0.5", "acc": 82.4, "what": "Каждая гармоника измеряется отдельно, с поправкой на жёсткость струны. Басы перестали ползти."}, {"v": "0.6", "acc": 83.0, "what": "Плавная стрелка: её скорость зависит от уверенности движка, а первые 0,3 с после щипка она быстрая."}, {"v": "0.6.2", "acc": 82.6, "what": "Атака щипка больше не сбивает стрелку. Режим «как в GuitarTuna». Для басовых строев окно анализа длиннее."}], "strings": [["E2", 82.41], ["A2", 110.0], ["D3", 146.83], ["G3", 196.0], ["B3", 246.94], ["E4", 329.63]], "race": [{"name": "Точное показание", "sub": "от щипка до точности ±10 ц, медиана", "vals": [0.24, 0.6200000000000001, 0.15]}, {"name": "Нота появляется", "sub": "записи гитары с телефона, среднее по 16 щипкам", "vals": [0.10291666666666678, 0.6857466666666793, 0.07599999999999994]}]};
(() => {
const reduce = matchMedia('(prefers-reduced-motion: reduce)').matches;
const css = n => getComputedStyle(document.documentElement).getPropertyValue(n).trim();
const el = (tag, a = {}, kids = []) => { const e = document.createElement(tag); for (const k in a) { if (k === 'class') e.className = a[k]; else if (k === 'text') e.textContent = a[k]; else e.setAttribute(k, a[k]); } kids.forEach(c => e.append(c)); return e; };
const S = (tag, a = {}, text) => { const e = document.createElementNS('http://www.w3.org/2000/svg', tag); for (const k in a) e.setAttribute(k, a[k]); if (text !== undefined) e.textContent = text; return e; };
const nf = (v, dp = 1) => { const s = Math.abs(v).toFixed(dp).replace('.', ','); return (v < 0 && Number(s.replace(',', '.')) !== 0 ? '−' : '') + s; };
const sg = (v, dp = 1) => (v > 0 && Number(v.toFixed(dp)) !== 0 ? '+' : '') + nf(v, dp);
const SER = [['aria', 'Aria 0.6.2', '--aria'], ['gt', 'GuitarTuna (сайт)', '--gt'], ['oss', 'aubio mcomb', '--oss']];
const svgOf = (host, W, H, label) => { const s = S('svg', { viewBox: `0 0 ${W} ${H}`, width: W, height: H, role: 'img', 'aria-label': label }); host.replaceChildren(s); return s; };

/* tooltip: values lead, labels follow; hover and keyboard focus */
const tip = document.getElementById('tip');
const showTip = (t, x, y) => { tip.replaceChildren(el('b', { text: t.dataset.v }), el('span', { text: t.dataset.k })); tip.hidden = false; const r = tip.getBoundingClientRect(); let l = x + 14, tp = y - r.height - 10; if (l + r.width > innerWidth - 8) l = x - r.width - 14; if (l < 8) l = 8; if (tp < 8) tp = y + 16; tip.style.left = l + 'px'; tip.style.top = tp + 'px'; };
document.addEventListener('pointermove', e => { const t = e.target.closest && e.target.closest('[data-v]'); t ? showTip(t, e.clientX, e.clientY) : (tip.hidden = true); });
document.addEventListener('focusin', e => { const t = e.target.closest && e.target.closest('[data-v]'); if (t) { const r = t.getBoundingClientRect(); showTip(t, r.left + r.width / 2, r.top); } });
document.addEventListener('focusout', () => { tip.hidden = true; });
addEventListener('scroll', () => { tip.hidden = true; }, { passive: true });

/* legend that focuses one series */
let focus = null;
function legend(id, kind, onChange) {
  const box = document.getElementById(id); box.replaceChildren();
  SER.forEach(([k, name, c]) => {
    const b = el('button', { type: 'button', 'aria-pressed': String(focus === k) }, [el('i', { class: kind, style: `background:var(${c})` }), document.createTextNode(name)]);
    b.addEventListener('click', () => { focus = focus === k ? null : k; drawAll(); });
    box.append(b);
  });
}
const applyFocus = svg => svg.querySelectorAll('[data-s]').forEach(g => g.classList.toggle('dim', focus !== null && g.dataset.s !== focus));

/* hero dial */
function drawDial() {
  const svg = document.getElementById('hdial'); svg.replaceChildren();
  const W = Math.max(320, Math.round(svg.parentNode.clientWidth || 560)), base = 112, m = 18, x = v => m + (v + 50) / 100 * (W - 2 * m);
  svg.setAttribute('viewBox', `0 0 ${W} 170`);
  for (let v = -50; v <= 50; v += 5) {
    const long = v % 25 === 0, len = v === 0 ? 34 : long ? 20 : 10;
    svg.append(S('line', { x1: x(v), x2: x(v), y1: base - len, y2: base, stroke: v === 0 ? '#fff' : '#555', 'stroke-width': v === 0 ? 1.6 : 1 }));
    if (long) svg.append(S('text', { x: x(v), y: base + 22, 'text-anchor': 'middle', fill: '#7e7e7e', 'font-family': css('--f-mono'), 'font-size': 13 }, v === 0 ? '0' : (v > 0 ? '+' : '−') + Math.abs(v)));
  }
  svg.append(S('line', { x1: m, x2: W - m, y1: base, y2: base, stroke: '#2e2e2e' }));
  const mk = (v, color, label, h, right) => {
    const g = S('g'); g.append(S('line', { x1: x(0), x2: x(0), y1: base - h, y2: base + 1, stroke: color, 'stroke-width': 3, 'stroke-linecap': 'round' }), S('circle', { cx: x(0), cy: base - h, r: 5, fill: color }),
      S('text', { x: x(0) + (right ? 11 : -11), y: base - h - 9, 'text-anchor': right ? 'start' : 'end', fill: '#f4f4f4', 'font-family': css('--f-sans'), 'font-size': 15, 'font-weight': 500 }, label));
    svg.append(g); return [g, x(v) - x(0)];
  };
  const [gt, gdx] = mk(P.ruler.gt, css('--gt'), `GuitarTuna +${P.ruler.gt}`, 46, true);
  const [ar] = mk(0, css('--aria'), 'Aria 0', 66, false);
  const place = (k) => { ar.setAttribute('transform', `translate(${(-60 * k).toFixed(2)} 0)`); gt.setAttribute('transform', `translate(${(gdx - 60 * k).toFixed(2)} 0)`); };
  place(0);
  if (!reduce) { const t0 = performance.now(); const tick = now => { const t = (now - t0) / 1000; const k = Math.exp(-3 * t) * Math.cos(8 * t); place(k); if (t < 2.4) requestAnimationFrame(tick); else place(0); }; requestAnimationFrame(tick); }
}

/* step glyphs */
function glyphs() {
  let d = ''; for (let i = 0; i <= 200; i++) { const x = 20 + i, t = i / 200, env = t < .1 ? 0 : Math.exp(-(t - .1) * 5.5); d += (i ? 'L' : 'M') + x + ',' + (38 + 26 * env * Math.sin(i * .9) + (t < .1 ? (Math.random() - .5) * 2 : 0)).toFixed(1); }
  document.getElementById('g1').setAttribute('d', d);
  const g2 = document.getElementById('g2'); for (let n = 1; n <= 9; n++) { const ideal = 14 + n * 23, real = ideal + n * n * .14; g2.append(S('circle', { cx: ideal, cy: 70, r: 1.8, fill: '#555' }), S('line', { x1: real, x2: real, y1: 66, y2: 66 - 54 / Math.sqrt(n), stroke: '#f4f4f4', 'stroke-width': 1.6 })); }
  const g3 = document.getElementById('g3'); let p = ''; for (let i = 0; i <= 220; i++) { const x = 10 + i, peak = Math.exp(-((i - 120) ** 2) / 30) * 50; p += (i ? 'L' : 'M') + x + ',' + (68 - peak - Math.abs(Math.sin(i * 1.7) * 7 + Math.sin(i * .63) * 5)).toFixed(1); }
  g3.append(S('path', { d: p, fill: 'none', stroke: '#9a9a9a', 'stroke-width': 1.2 }), S('circle', { cx: 130, cy: 16, r: 4, fill: '#4b93ea' }));
  const g4 = document.getElementById('g4'); for (let v = -40; v <= 40; v += 10) g4.append(S('line', { x1: 120 + v * 2.4, x2: 120 + v * 2.4, y1: v === 0 ? 40 : 52, y2: 64, stroke: v === 0 ? '#fff' : '#555' }));
  let tr = ''; for (let i = 0; i <= 60; i++) { const t = i / 60, v = -34 * Math.exp(-t * 4.5) * Math.cos(t * 9); tr += (i ? 'L' : 'M') + (120 + v * 2.4).toFixed(1) + ',' + (8 + t * 50).toFixed(1); }
  g4.append(S('path', { d: tr, fill: 'none', stroke: '#4b93ea', 'stroke-width': 1.6 }));
}

/* bass explorer */
const st = { s: 0, B: 60e-6 };
function bAsText(B) { if (B === 0) return '0'; const e = Math.floor(Math.log10(B)), m = B / 10 ** e; const sup = { '-4': '⁻⁴', '-5': '⁻⁵', '-6': '⁻⁶' }[e] || ('e' + e); return `${nf(m, m % 1 ? 1 : 0)}·10${sup}`; }
function drawHarm() {
  const host = document.getElementById('harm'), W = host.clientWidth, H = W < 520 ? 240 : 290, m = { l: 34, r: 8, t: 24, b: 40 };
  const [name, f0] = P.strings[st.s], B = st.B, N = Math.min(13, 1450 / f0);
  const cents = n => 600 * Math.log2((1 + B * n * n) / (1 + B));
  const max = 25, n = 13, bw = (W - m.l - m.r) / n, y = v => m.t + (1 - Math.min(v, max) / max) * (H - m.t - m.b);
  const svg = svgOf(host, W, H, `Сдвиг гармоник струны ${name}`);
  const g = S('g', { class: 'grid' }), ax = S('g', { class: 'ax' });
  for (let v = 0; v <= max; v += 5) { g.append(S('line', { x1: m.l, x2: W - m.r, y1: y(v), y2: y(v) })); ax.append(S('text', { x: m.l - 8, y: y(v) + 4, 'text-anchor': 'end' }, String(v))); }
  svg.append(g, ax);
  const th = Math.min(22, bw * .6);
  for (let k = 1; k <= n; k++) {
    const cx = m.l + bw * (k - .5), c = cents(k), top = y(c), h = Math.max(.5, y(0) - top), used = k <= N + 1e-9, r = Math.min(3, h);
    svg.append(S('path', { d: `M${cx - th / 2},${y(0)}V${top + r}Q${cx - th / 2},${top} ${cx - th / 2 + r},${top}H${cx + th / 2 - r}Q${cx + th / 2},${top} ${cx + th / 2},${top + r}V${y(0)}Z`, fill: used ? css('--bar') : '#333' }));
    svg.append(S('text', { class: 'lbl', x: cx, y: y(0) + 17, 'text-anchor': 'middle' }, String(k)));
    svg.append(S('rect', { class: 'hit', x: cx - bw / 2, y: m.t, width: bw, height: H - m.t - m.b, tabindex: 0, 'data-v': `+${nf(c, 2)} ц`, 'data-k': `${k}-я гармоника, ${Math.round(k * f0)} Гц${used ? '' : ', выше 1450 Гц'}` }));
  }
  const xN = m.l + bw * (N - .5), cN = cents(N);
  svg.append(S('line', { x1: xN, x2: xN, y1: m.t - 8, y2: y(0), stroke: css('--gt'), 'stroke-width': 1.5 }));
  svg.append(S('text', { class: 'lbl ink', x: xN + (N > 10 ? -8 : 8), y: m.t - 12, 'text-anchor': N > 10 ? 'end' : 'start' }, `читает здесь: +${nf(cN)} ц`));
  svg.append(S('line', { x1: m.l, x2: W - m.r, y1: y(0), y2: y(0), stroke: '#555' }));
  svg.append(S('text', { class: 'lbl', x: W - m.r, y: H - 4, 'text-anchor': 'end' }, 'номер гармоники →'));
  document.getElementById('rdTop').textContent = sg(cN);
  document.getElementById('rdTopSub').textContent = `гармоника ${nf(N, N % 1 ? 1 : 0)}, ≈${Math.round(N * f0)} Гц`;
  document.getElementById('bVal').textContent = bAsText(B);
}
function setupExplorer() {
  const seg = document.getElementById('strSeg');
  P.strings.forEach(([n], i) => { const b = el('button', { type: 'button', 'aria-pressed': String(i === st.s), text: n }); b.addEventListener('click', () => { st.s = i; seg.querySelectorAll('button').forEach((x, j) => x.setAttribute('aria-pressed', String(j === i))); drawHarm(); }); seg.append(b); });
  const sl = document.getElementById('bSlider'); sl.addEventListener('input', () => { st.B = Number(sl.value) * 1e-6; drawHarm(); });
}

/* bass test dot plot */
function drawBass() {
  legend('bassLegend', 'dot');
  const host = document.getElementById('bassPlot'), W = host.clientWidth, narrow = W < 600, rowH = narrow ? 60 : 44, left = narrow ? 4 : 220, right = 20, top = 6, rows = P.bass;
  const H = top + rows.length * rowH + 44, lo = -5, hi = 20, x = v => left + (v - lo) / (hi - lo) * (W - left - right);
  const svg = svgOf(host, W, H, 'Показания тюнеров на точно настроенных струнах');
  const g = S('g', { class: 'grid' }), ax = S('g', { class: 'ax' });
  for (let v = lo; v <= hi; v += 5) { g.append(S('line', { x1: x(v), x2: x(v), y1: top, y2: top + rows.length * rowH })); ax.append(S('text', { x: x(v), y: top + rows.length * rowH + 18, 'text-anchor': 'middle' }, (v > 0 ? '+' : v < 0 ? '−' : '') + Math.abs(v))); }
  svg.append(g, ax, S('line', { x1: x(0), x2: x(0), y1: top, y2: top + rows.length * rowH, stroke: '#fff', 'stroke-width': 1.2 }));
  svg.append(S('text', { class: 'lbl', x: W - right, y: H - 4, 'text-anchor': 'end' }, 'центы от правильного ответа'));
  rows.forEach((r, i) => {
    const y0 = top + i * rowH, cy = narrow ? y0 + 40 : y0 + rowH / 2;
    if (i === 2) svg.append(S('line', { x1: 0, x2: W - right, y1: y0, y2: y0, stroke: '#333' }));
    svg.append(S('text', { class: 'lbl ' + (r.real ? 'ink' : 'ink2'), x: 0, y: narrow ? y0 + 18 : cy + 4 }, r.name));
    SER.forEach(([k, name, c], j) => {
      const v = r[k], cyj = cy + (j - 1) * 8, gg = S('g', { 'data-s': k });
      gg.append(S('circle', { cx: x(v), cy: cyj, r: 5.5, fill: css(c), stroke: css('--bg'), 'stroke-width': 2 }), S('circle', { class: 'hit', cx: x(v), cy: cyj, r: 12, tabindex: 0, 'data-v': `${sg(v)} ц`, 'data-k': `${name} · ${r.name}` }));
      if (k === 'gt' && v > 3) gg.append(S('text', { class: 'lbl ink2', x: x(v) + 11, y: cyj + 4 }, sg(v, 0) + ' ц'));
      svg.append(gg);
    });
  });
  applyFocus(svg);
}

/* accuracy by condition: grouped horizontal bars, values at the tips, grow in on first view */
function drawAcc() {
  legend('accLegend', '');
  const host = document.getElementById('acc'), W = host.clientWidth, narrow = W < 620, left = narrow ? 0 : 210, right = 46, bh = 9, gap = 3;
  const groupH = narrow ? 3 * (bh + gap) + 40 : Math.max(46, 3 * (bh + gap) + 18), top = 4;
  const H = top + P.conds.length * groupH + 30, x = v => left + v / 100 * (W - left - right);
  const svg = svgOf(host, W, H, 'Доля времени с точностью ±5 центов по условиям');
  const g = S('g', { class: 'grid' }), ax = S('g', { class: 'ax' });
  for (let v = 0; v <= 100; v += 25) { g.append(S('line', { x1: x(v), x2: x(v), y1: top, y2: top + P.conds.length * groupH })); ax.append(S('text', { x: x(v), y: H - 6, 'text-anchor': 'middle' }, v + (v === 100 ? ' %' : ''))); }
  svg.append(g, ax);
  P.conds.forEach((c, i) => {
    const y0 = top + i * groupH, by = narrow ? y0 + 34 : y0 + (groupH - 3 * (bh + gap)) / 2;
    svg.append(S('text', { class: 'lbl ink', x: 0, y: narrow ? y0 + 14 : by + 10, 'font-family': css('--f-sans') }, c.name));
    svg.append(S('text', { class: 'lbl', x: 0, y: narrow ? y0 + 28 : by + 26 }, narrow ? c.sub : ''));
    SER.forEach(([k, name, col], j) => {
      const v = c[k], yy = by + j * (bh + gap), w = x(v) - x(0), gg = S('g', { 'data-s': k });
      const bar = S('path', { d: `M${x(0)},${yy}H${x(0) + w - 3}Q${x(0) + w},${yy} ${x(0) + w},${yy + 3}V${yy + bh - 3}Q${x(0) + w},${yy + bh} ${x(0) + w - 3},${yy + bh}H${x(0)}Z`, fill: css(col), class: 'grow', style: `transform-origin:${x(0)}px 0` });
      gg.append(bar, S('text', { class: 'lbl ink2', x: x(v) + 6, y: yy + bh - 1 }, nf(v)),
        S('rect', { class: 'hit', x: x(0), y: yy - 1, width: Math.max(w, 24), height: bh + 2, tabindex: 0, 'data-v': nf(v) + ' %', 'data-k': `${name} · ${c.name}` }));
      svg.append(gg);
    });
  });
  applyFocus(svg);
  growOnce(host);
}
function growOnce(host) {
  if (reduce || host.dataset.grown) return;
  const io = new IntersectionObserver(([e]) => { if (e.isIntersecting) { host.dataset.grown = '1'; host.querySelectorAll('.grow').forEach((b, i) => b.animate([{ transform: 'scaleX(0)' }, { transform: 'scaleX(1)' }], { duration: 900, delay: (i % 3) * 70 + Math.floor(i / 3) * 40, easing: 'cubic-bezier(.16,1,.3,1)' })); io.disconnect(); } }, { threshold: .35 });
  io.observe(host);
}

/* speed race */
let raceT = null;
function drawRace(t) {
  const host = document.getElementById('race'), W = host.clientWidth, narrow = W < 620, left = narrow ? 0 : 210, right = 70, laneH = 22, top = 6;
  const groupH = narrow ? 3 * laneH + 40 : 3 * laneH + 26, tmax = 0.8, H = top + P.race.length * groupH + 34;
  const x = v => left + Math.min(v, tmax) / tmax * (W - left - right);
  const svg = svgOf(host, W, H, 'Время от щипка до показания');
  const g = S('g', { class: 'grid' }), ax = S('g', { class: 'ax' });
  for (let v = 0; v <= tmax + 1e-9; v += 0.2) { g.append(S('line', { x1: x(v), x2: x(v), y1: top, y2: top + P.race.length * groupH })); ax.append(S('text', { x: x(v), y: H - 6, 'text-anchor': 'middle' }, nf(v, 1) + (v >= tmax - 1e-9 ? ' с' : ''))); }
  svg.append(g, ax);
  P.race.forEach((r, i) => {
    const y0 = top + i * groupH, ly = narrow ? y0 + 34 : y0 + 10;
    svg.append(S('text', { class: 'lbl ink', x: 0, y: narrow ? y0 + 14 : ly + 6, 'font-family': css('--f-sans') }, r.name));
    if (!narrow) svg.append(S('text', { class: 'lbl', x: 0, y: ly + 22 }, r.sub.split(',')[0]));
    SER.forEach(([k, name, col], j) => {
      const v = r.vals[j], cy = ly + j * laneH + (narrow ? 6 : 0), now = t === null ? v : Math.min(t, v), done = t === null || t >= v;
      svg.append(S('line', { x1: x(0), x2: x(now), y1: cy, y2: cy, stroke: css(col), 'stroke-width': 2, 'stroke-linecap': 'round' }));
      svg.append(S('circle', { cx: x(now), cy, r: done ? 5 : 4, fill: css(col), stroke: css('--bg'), 'stroke-width': 2 }));
      if (done) svg.append(S('text', { class: 'lbl ink2', x: x(v) + 10, y: cy + 4 }, `${nf(v, 2)} с`));
      svg.append(S('rect', { class: 'hit', x: x(0), y: cy - 9, width: Math.max(24, x(v) - x(0) + 10), height: 18, tabindex: 0, 'data-v': `${nf(v, 2)} с`, 'data-k': `${name} · ${r.name}` }));
    });
  });
  document.getElementById('clock').textContent = nf(t === null ? Math.max(...P.race.flatMap(r => r.vals)) : t, 2) + ' с';
}
function raceLegend() { const box = document.getElementById('raceLegend'); box.replaceChildren(...SER.map(([k, name, c]) => el('span', { style: 'display:inline-flex;align-items:center;gap:8px;padding:5px 10px 5px 0' }, [el('i', { class: 'dot', style: `background:var(${c})` }), document.createTextNode(name)]))); }
function runRace() {
  if (reduce) { raceT = null; drawRace(null); return; }
  const t0 = performance.now(), end = Math.max(...P.race.flatMap(r => r.vals)) + 0.02;
  const tick = now => { const t = (now - t0) / 1000 / 3; if (t < end) { raceT = t; drawRace(t); requestAnimationFrame(tick); } else { raceT = null; drawRace(null); } };
  requestAnimationFrame(tick);
}

/* tables */
function table(id, head, rows) {
  const t = document.getElementById(id); t.replaceChildren();
  const hr = el('tr'); head.forEach(h => hr.append(el('th', { scope: 'col' }, [document.createTextNode(h[0]), ...(h[1] ? [el('small', { text: h[1] })] : [])])));
  t.append(el('thead', {}, [hr]));
  const tb = el('tbody');
  rows.forEach(r => {
    if (r.cat) { tb.append(el('tr', { class: 'cat' }, [el('th', { colspan: String(head.length), text: r.cat })])); return; }
    const tr = el('tr', { class: r.mine ? 'mine' : '' });
    tr.append(el('th', { scope: 'row' }, [document.createTextNode(r.name), ...(r.note ? [el('small', { text: r.note })] : [])]));
    const nums = r.vals.filter(v => typeof v === 'number');
    const best = r.better && nums.length > 1 ? (r.better === 'hi' ? Math.max(...nums) : r.better === 'zero' ? nums.reduce((a, b) => Math.abs(b) < Math.abs(a) ? b : a) : Math.min(...nums)) : null;
    r.vals.forEach(v => {
      if (v === null || v === undefined) return tr.append(el('td', { text: '—' }));
      if (typeof v === 'string') return tr.append(el('td', { text: v }));
      const isBest = best !== null && (r.better === 'zero' ? Math.abs(v) === Math.abs(best) : v === best);
      tr.append(el('td', { class: isBest ? 'best' : '', text: (r.signed ? sg(v, r.dp ?? 1) : nf(v, r.dp ?? 1)) + (r.unit || '') }));
    });
    tb.append(tr);
  });
  t.append(tb);
}

/* stuck needle: before/after rows on one % scale */
function drawSlope() {
  const box = document.getElementById('slopeLegend'); box.replaceChildren(
    el('span', { style: 'display:inline-flex;align-items:center;gap:8px;padding:5px 10px' }, [el('i', { class: 'dot', style: 'background:#8a8a8a' }), document.createTextNode('Aria 0.6')]),
    el('span', { style: 'display:inline-flex;align-items:center;gap:8px;padding:5px 10px' }, [el('i', { class: 'dot', style: 'background:var(--aria)' }), document.createTextNode('0.6.2')]),
    el('span', { style: 'display:inline-flex;align-items:center;gap:8px;padding:5px 10px' }, [el('i', { class: 'dot', style: 'background:transparent;box-shadow:inset 0 0 0 2px var(--aria)' }), document.createTextNode('0.6.2, как в GuitarTuna')]));
  const host = document.getElementById('slope'), W = host.clientWidth, narrow = W < 620, rowH = narrow ? 62 : 48, left = narrow ? 4 : 240, right = narrow ? 8 : 150, top = 6, rows = P.stuck;
  const H = top + rows.length * rowH + 40, max = 32, x = v => left + v / max * (W - left - right);
  const svg = svgOf(host, W, H, 'Застревание стрелки: до и после исправления');
  const g = S('g', { class: 'grid' }), ax = S('g', { class: 'ax' });
  for (let v = 0; v <= 30; v += 5) { g.append(S('line', { x1: x(v), x2: x(v), y1: top, y2: top + rows.length * rowH })); ax.append(S('text', { x: x(v), y: top + rows.length * rowH + 18, 'text-anchor': 'middle' }, v + (v === 30 ? ' %' : ''))); }
  svg.append(g, ax);
  const names = ['Aria 0.6', 'Aria 0.6.2', '0.6.2, как в GuitarTuna'];
  rows.forEach((r, i) => {
    const y0 = top + i * rowH, cy = narrow ? y0 + 42 : y0 + rowH / 2, [a, b, c] = r.vals;
    svg.append(S('text', { class: 'lbl ink', x: 0, y: narrow ? y0 + 18 : cy + 4 }, r.name));
    svg.append(S('line', { x1: x(Math.min(a, b, c)), x2: x(Math.max(a, b, c)), y1: cy, y2: cy, stroke: '#444', 'stroke-width': 2 }));
    svg.append(S('circle', { cx: x(a), cy, r: 5.5, fill: '#8a8a8a', stroke: css('--bg'), 'stroke-width': 2 }));
    svg.append(S('circle', { cx: x(c), cy, r: 6, fill: css('--bg'), stroke: css('--aria'), 'stroke-width': 2 }));
    svg.append(S('circle', { cx: x(b), cy, r: 5.5, fill: css('--aria'), stroke: css('--bg'), 'stroke-width': 2 }));
    [a, b, c].forEach((v, j) => svg.append(S('circle', { class: 'hit', cx: x(v), cy, r: 11, tabindex: 0, 'data-v': `${nf(v)} %`, 'data-k': `${r.name} · ${names[j]}` })));
    const txt = `${nf(a)} → ${nf(b)} · ${nf(c)}`;
    svg.append(S('text', { class: 'lbl ink2', x: narrow ? W - right : W - right + 14, y: narrow ? y0 + 18 : cy + 4, 'text-anchor': narrow ? 'end' : 'start' }, txt));
  });
}

/* GT mode: dumbbell per string */
function drawDumb() {
  const box = document.getElementById('dumbLegend'); box.replaceChildren(
    el('span', { style: 'display:inline-flex;align-items:center;gap:8px;padding:5px 10px' }, [el('i', { class: 'dot', style: 'background:#8a8a8a' }), document.createTextNode('обычный режим')]),
    el('span', { style: 'display:inline-flex;align-items:center;gap:8px;padding:5px 10px' }, [el('i', { class: 'dot', style: 'background:var(--aria)' }), document.createTextNode('как в GuitarTuna')]));
  const host = document.getElementById('dumb'), W = host.clientWidth, rowH = 40, left = 64, right = 24, top = 8, rows = P.live;
  const H = top + rows.length * rowH + 40, lo = -14, hi = 6, x = v => left + (v - lo) / (hi - lo) * (W - left - right);
  const svg = svgOf(host, W, H, 'Разница с GuitarTuna по струнам');
  const g = S('g', { class: 'grid' }), ax = S('g', { class: 'ax' });
  for (let v = lo; v <= hi; v += 2) { g.append(S('line', { x1: x(v), x2: x(v), y1: top, y2: top + rows.length * rowH })); if (v % 4 === 0 || v === -14) ax.append(S('text', { x: x(v), y: top + rows.length * rowH + 18, 'text-anchor': 'middle' }, (v > 0 ? '+' : v < 0 ? '−' : '') + Math.abs(v))); }
  svg.append(g, ax, S('line', { x1: x(0), x2: x(0), y1: top, y2: top + rows.length * rowH, stroke: '#fff', 'stroke-width': 1.2 }));
  svg.append(S('text', { class: 'lbl', x: W - right, y: H - 4, 'text-anchor': 'end' }, 'центы, Aria минус GuitarTuna'));
  rows.forEach((r, i) => {
    const cy = top + i * rowH + rowH / 2, [a, b] = r.vals;
    svg.append(S('text', { class: 'lbl ink', x: 0, y: cy + 4 }, r.name));
    svg.append(S('line', { x1: x(a), x2: x(b), y1: cy, y2: cy, stroke: '#555', 'stroke-width': 2 }));
    svg.append(S('circle', { cx: x(a), cy, r: 5, fill: '#8a8a8a', stroke: css('--bg'), 'stroke-width': 2 }), S('circle', { cx: x(b), cy, r: 6, fill: css('--aria'), stroke: css('--bg'), 'stroke-width': 2 }));
    svg.append(S('circle', { class: 'hit', cx: x(a), cy, r: 12, tabindex: 0, 'data-v': `${sg(a)} ц`, 'data-k': `${r.name} · обычный режим` }), S('circle', { class: 'hit', cx: x(b), cy, r: 12, tabindex: 0, 'data-v': `${sg(b)} ц`, 'data-k': `${r.name} · как в GuitarTuna` }));
    if (Math.abs(a - b) > 1.5) svg.append(S('text', { class: 'lbl ink2', x: x(a) - 9, y: cy + 4, 'text-anchor': 'end' }, sg(a)));
  });
}

/* live strings: harmonic corridor with both tuners */
function drawRange() {
  const box = document.getElementById('rangeLegend'); box.replaceChildren(
    el('span', { style: 'display:inline-flex;align-items:center;gap:8px;padding:5px 10px' }, [el('i', { style: 'background:#5a5a5a;width:16px;height:8px' }), document.createTextNode('1-я и 2-я гармоники')]),
    el('span', { style: 'display:inline-flex;align-items:center;gap:8px;padding:5px 10px' }, [el('i', { class: 'dot', style: 'background:var(--aria)' }), document.createTextNode('Aria 0.6.2')]),
    el('span', { style: 'display:inline-flex;align-items:center;gap:8px;padding:5px 10px' }, [el('i', { class: 'dot', style: 'background:var(--gt)' }), document.createTextNode('GuitarTuna (сайт)')]));
  const host = document.getElementById('range'), W = host.clientWidth, narrow = W < 600, rowH = narrow ? 52 : 38, catH = 30, left = narrow ? 4 : 230, right = 20, top = 4;
  let H = top + 44; P.real.forEach(r => H += r.cat ? catH : rowH);
  const lo = -10, hi = 18, x = v => left + (v - lo) / (hi - lo) * (W - left - right);
  const svg = svgOf(host, W, H, 'Показания тюнеров и коридор гармоник на живых струнах');
  const g = S('g', { class: 'grid' }), ax = S('g', { class: 'ax' }); svg.append(g, ax);
  let yy = top;
  P.real.forEach(r => {
    if (r.cat) { svg.append(S('text', { class: 'lbl', x: 0, y: yy + 20 }, r.cat)); yy += catH; return; }
    const cy = narrow ? yy + 36 : yy + rowH / 2, [h1, h2, a, gt] = r.vals, lo2 = Math.min(h1, h2), hi2 = Math.max(h1, h2);
    svg.append(S('text', { class: 'lbl ink', x: 0, y: narrow ? yy + 16 : cy + 4 }, `${r.name}${r.note ? ' · ' + r.note : ''}`));
    svg.append(S('rect', { x: x(lo2), y: cy - 5, width: Math.max(3, x(hi2) - x(lo2)), height: 10, fill: '#4a4a4a', rx: 2 }));
    svg.append(S('rect', { class: 'hit', x: x(lo2), y: cy - 9, width: Math.max(16, x(hi2) - x(lo2)), height: 18, tabindex: 0, 'data-v': `${sg(h1)} … ${sg(h2)} ц`, 'data-k': `${r.name}: 1-я и 2-я гармоники` }));
    svg.append(S('circle', { cx: x(gt), cy, r: 5.5, fill: css('--gt'), stroke: css('--bg'), 'stroke-width': 2 }), S('circle', { cx: x(a), cy, r: 5.5, fill: css('--aria'), stroke: css('--bg'), 'stroke-width': 2 }));
    svg.append(S('circle', { class: 'hit', cx: x(a), cy, r: 11, tabindex: 0, 'data-v': `${sg(a)} ц`, 'data-k': `Aria · ${r.name}` }), S('circle', { class: 'hit', cx: x(gt), cy, r: 11, tabindex: 0, 'data-v': `${sg(gt)} ц`, 'data-k': `GuitarTuna · ${r.name}` }));
    svg.append(S('text', { class: 'lbl ink2', x: x(gt) + 10, y: cy + 4 }, sg(gt, 0)));
    yy += rowH;
  });
  for (let v = lo; v <= hi; v += 2) { g.append(S('line', { x1: x(v), x2: x(v), y1: top, y2: yy })); if (v % 4 === 0) ax.append(S('text', { x: x(v), y: yy + 18, 'text-anchor': 'middle' }, (v > 0 ? '+' : v < 0 ? '−' : '') + Math.abs(v))); }
  svg.append(S('line', { x1: x(0), x2: x(0), y1: top, y2: yy, stroke: '#fff', 'stroke-width': 1.2 }));
  svg.append(S('text', { class: 'lbl', x: W - right, y: yy + 38, 'text-anchor': 'end' }, 'центы от ноты'));
}

/* versions: line chart with direct labels */
function drawVer() {
  const host = document.getElementById('ver'), W = host.clientWidth, H = W < 600 ? 230 : 260, m = { l: 40, r: 40, t: 30, b: 34 };
  const vs = P.versions, lo = 60, hi = 90, x = i => m.l + i * (W - m.l - m.r) / (vs.length - 1), y = v => m.t + (1 - (v - lo) / (hi - lo)) * (H - m.t - m.b);
  const svg = svgOf(host, W, H, 'Точность Aria по версиям');
  const g = S('g', { class: 'grid' }), ax = S('g', { class: 'ax' });
  for (let v = lo; v <= hi; v += 10) { g.append(S('line', { x1: m.l, x2: W - m.r, y1: y(v), y2: y(v) })); ax.append(S('text', { x: m.l - 8, y: y(v) + 4, 'text-anchor': 'end' }, v + ' %')); }
  svg.append(g, ax);
  const path = S('path', { d: vs.map((v, i) => (i ? 'L' : 'M') + x(i) + ',' + y(v.acc)).join(''), fill: 'none', stroke: css('--aria'), 'stroke-width': 2, 'stroke-linejoin': 'round' });
  svg.append(path);
  vs.forEach((v, i) => {
    svg.append(S('circle', { cx: x(i), cy: y(v.acc), r: 5, fill: i === vs.length - 1 ? css('--aria') : css('--bg'), stroke: css('--aria'), 'stroke-width': 2 }));
    svg.append(S('text', { class: 'lbl ink', x: x(i), y: y(v.acc) - 12, 'text-anchor': 'middle' }, nf(v.acc)));
    svg.append(S('text', { class: 'lbl ink2', x: x(i), y: H - 10, 'text-anchor': 'middle' }, v.v));
    svg.append(S('circle', { class: 'hit', cx: x(i), cy: y(v.acc), r: 14, tabindex: 0, 'data-v': `${nf(v.acc)} %`, 'data-k': `Aria ${v.v}` }));
  });
  if (!reduce && !host.dataset.drawn) { host.dataset.drawn = '1'; const len = path.getTotalLength(); path.style.strokeDasharray = len; path.style.strokeDashoffset = 0;
    const io = new IntersectionObserver(([e]) => { if (e.isIntersecting) { path.animate([{ strokeDashoffset: len }, { strokeDashoffset: 0 }], { duration: 1400, easing: 'cubic-bezier(.16,1,.3,1)' }); io.disconnect(); } }, { threshold: .4 }); io.observe(host); }
  const log = document.getElementById('verLog'); log.replaceChildren();
  vs.forEach(v => log.append(el('li', {}, [el('b', { text: `Aria ${v.v} · ${nf(v.acc)} %` }), el('span', { text: v.what })])));
}

function drawAll() { drawDial(); drawHarm(); drawBass(); drawAcc(); drawRace(raceT); drawSlope(); drawDumb(); drawRange(); drawVer(); }
glyphs(); setupExplorer(); raceLegend();
table('cmpTable', [['', ''], ['Aria 0.6.2', ''], ['GuitarTuna', 'сайт'], ['aubio mcomb', '']], P.cmp);
table('rankTable', [['', ''], ['Точность ±5 ц', ''], ['Узнаёт ноту', '±50 ц'], ['Точное показание', '']], P.rank);
table('probeTable', [['', ''], ['Правильно', ''], ['GuitarTuna', 'приложение'], ['GuitarTuna', 'сайт'], ['Aria', 'обычный'], ['Aria', 'как в GT']], P.probe);
drawAll();
document.getElementById('replay').addEventListener('click', runRace);
if (!reduce) { const io = new IntersectionObserver(([e]) => { if (e.isIntersecting) { runRace(); io.disconnect(); } }, { threshold: .5 }); io.observe(document.getElementById('race')); }
let rz; addEventListener('resize', () => { clearTimeout(rz); rz = setTimeout(drawAll, 120); });
if (document.fonts && document.fonts.ready) document.fonts.ready.then(drawAll);
})();
</script>
</body>
</html>
