/* =====================================================================
   textures.js — процедурная генерация PBR-материалов.
   Ничего не грузится с диска: каждый пиксель альбедо, карты нормалей,
   шероховатости, высоты (для parallax) и AO считается кодом.
   Разрешение 256x256 на блок — в 16 раз детальнее классического вокселя.
   ===================================================================== */
(function (global) {
  'use strict';
  var N = global.NZ;
  var clamp = N.clamp, lerp = N.lerp, ss = N.smoothstep;

  // Список слоёв текстурного массива
  var TILES = {
    GRASS_TOP: 0, GRASS_SIDE: 1, DIRT: 2, ROCK: 3, COBBLE: 4, SAND: 5,
    PLANKS: 6, BARK: 7, LOG_TOP: 8, LEAVES: 9, BRICK: 10, GRAVEL: 11,
    ROOF: 12, HEDGE: 13, FLAGSTONE: 14, MUD: 15, TALL_GRASS: 16, BUSH: 17, VINE: 18
  };
  var TILE_COUNT = 19;

  // ---------------------------------------------------------------
  //  Холст одного тайла
  // ---------------------------------------------------------------
  function Tile(S) {
    this.S = S;
    this.alb = new Float32Array(S * S * 3);
    this.h = new Float32Array(S * S);
    this.rg = new Float32Array(S * S);
    this.al = new Float32Array(S * S);
    this.bump = 1.0;     // сила рельефа в карте нормалей
    this.paraScale = 0.0; // глубина parallax occlusion mapping
    this.aoStrength = 3.0;
    for (var i = 0; i < S * S; i++) { this.al[i] = 1; this.rg[i] = 0.8; }
  }
  Tile.prototype.set = function (x, y, r, g, b, h, rough, a) {
    var S = this.S;
    x = ((x % S) + S) % S; y = ((y % S) + S) % S;
    var i = y * S + x, j = i * 3;
    this.alb[j] = r; this.alb[j + 1] = g; this.alb[j + 2] = b;
    this.h[i] = h; this.rg[i] = rough;
    if (a !== undefined) this.al[i] = a;
  };
  Tile.prototype.flipY = function () {
    var S = this.S;
    for (var y = 0; y < S >> 1; y++) for (var x = 0; x < S; x++) {
      var i = y * S + x, j = (S - 1 - y) * S + x;
      var t;
      for (var c = 0; c < 3; c++) { t = this.alb[i * 3 + c]; this.alb[i * 3 + c] = this.alb[j * 3 + c]; this.alb[j * 3 + c] = t; }
      t = this.h[i]; this.h[i] = this.h[j]; this.h[j] = t;
      t = this.rg[i]; this.rg[i] = this.rg[j]; this.rg[j] = t;
      t = this.al[i]; this.al[i] = this.al[j]; this.al[j] = t;
    }
  };
  Tile.prototype.blend = function (x, y, r, g, b, h, rough, k) {
    var S = this.S;
    x = ((x % S) + S) % S; y = ((y % S) + S) % S;
    var i = y * S + x, j = i * 3;
    this.alb[j] = lerp(this.alb[j], r, k);
    this.alb[j + 1] = lerp(this.alb[j + 1], g, k);
    this.alb[j + 2] = lerp(this.alb[j + 2], b, k);
    this.h[i] = lerp(this.h[i], h, k);
    this.rg[i] = lerp(this.rg[i], rough, k);
    if (this.al[i] < 1) this.al[i] = lerp(this.al[i], 1, k);
  };

  // ---------------------------------------------------------------
  //  Генераторы отдельных материалов
  // ---------------------------------------------------------------

  // Трава сверху: рисуем тысячи отдельных травинок штрихами
  function genGrassTop(T, seed) {
    var S = T.S, rnd = N.mulberry32(seed);
    // почва под травой
    for (var y = 0; y < S; y++) for (var x = 0; x < S; x++) {
      var u = x / S, v = y / S;
      var d = N.fbm(u * 14, v * 14, 14, seed, 5);
      var d2 = N.fbm(u * 40, v * 40, 40, seed + 9, 3);
      var br = 0.10 + 0.05 * d + 0.03 * d2;
      T.set(x, y, br * 1.15, br * 0.95, br * 0.6, 0.15 + 0.1 * d, 0.95);
    }
    // крупные пятна оттенка (выгоревшая / сочная трава)
    function patch(u, v) { return N.fbm(u * 2.2, v * 2.2, 2, seed + 77, 4); }
    var blades = Math.round(S * S * 0.80);
    for (var b = 0; b < blades; b++) {
      var bx = rnd() * S, by = rnd() * S;
      var pu = bx / S, pv = by / S;
      var p = patch(pu, pv);
      var len = (0.05 + rnd() * rnd() * 0.16) * S;
      var ang = -Math.PI / 2 + (rnd() - 0.5) * 1.7;
      var curve = (rnd() - 0.5) * 0.05;
      // цвет травинки
      var t = rnd();
      var baseR = lerp(0.055, 0.30, p * p) + t * 0.10;
      var baseG = lerp(0.19, 0.56, p) + t * 0.13;
      var baseB = lerp(0.030, 0.11, p * 0.6) + t * 0.03;
      var w = rnd() < 0.25 ? 1.6 : 1.0;
      var steps = Math.max(3, Math.round(len));
      for (var s = 0; s < steps; s++) {
        var f = s / steps;
        ang += curve;
        var px = bx + Math.cos(ang) * len * f;
        var py = by + Math.sin(ang) * len * f;
        // к кончику светлее и желтее
        var tip = f * f;
        var r = baseR * (1 + tip * 1.05) + tip * 0.16;
        var g = baseG * (1 + tip * 0.70) + tip * 0.22;
        var bl = baseB * (1 + tip * 0.5);
        var hh = 0.25 + f * 0.7;
        for (var o = 0; o < w; o += 0.5) {
          T.blend(Math.round(px + o * 0.7), Math.round(py), r, g, bl, hh, 0.72, 0.9);
        }
      }
    }
    // редкие цветы
    for (var fl = 0; fl < S * 0.09; fl++) {
      var fx = rnd() * S, fy = rnd() * S;
      var c = rnd();
      var cr = c < 0.5 ? 0.95 : 0.95, cg = c < 0.5 ? 0.92 : 0.85, cb = c < 0.5 ? 0.72 : 0.25;
      for (var dy = -1; dy <= 1; dy++) for (var dx = -1; dx <= 1; dx++) {
        if (Math.abs(dx) + Math.abs(dy) > 1) continue;
        T.blend(Math.round(fx) + dx, Math.round(fy) + dy, cr, cg, cb, 0.8, 0.65, 0.85);
      }
    }
    T.bump = 1.5; T.paraScale = 0.0; T.aoStrength = 2.0;
  }

  // Земля с травяным козырьком сверху (боковая грань блока)
  function genGrassSide(T, seed) {
    genDirt(T, seed + 3);
    var S = T.S, rnd = N.mulberry32(seed + 21);
    var edge = Math.round(S * 0.30);
    for (var x = 0; x < S; x++) {
      var lip = edge + Math.round((N.fbm(x / S * 8, 0.3, 8, seed, 4) - 0.5) * S * 0.22);
      for (var y = 0; y < lip; y++) {
        var f = 1 - y / Math.max(1, lip);
        var p = N.fbm(x / S * 4, y / S * 4, 4, seed + 77, 4);
        var r = lerp(0.12, 0.26, p), g = lerp(0.30, 0.52, p), b = lerp(0.06, 0.13, p);
        var sh = 0.55 + 0.45 * f;
        T.set(x, y, r * sh, g * sh, b * sh, 0.55 + 0.35 * f, 0.75);
      }
      // отдельные травинки, свисающие вниз
      for (var k = 0; k < 3; k++) {
        var bx = x, by = lip;
        var len = rnd() * S * 0.10;
        for (var s = 0; s < len; s++) {
          var f2 = 1 - s / Math.max(1, len);
          if (rnd() < 0.45) continue;
          var pp = N.fbm(bx / S * 4, 0.5, 4, seed + 77, 3);
          T.blend(Math.round(bx + (rnd() - 0.5) * 2), Math.round(by + s),
            lerp(0.11, 0.24, pp) * f2, lerp(0.28, 0.48, pp) * f2, lerp(0.05, 0.12, pp) * f2, 0.5, 0.78, 0.85);
        }
      }
    }
    T.flipY();
    T.bump = 1.4; T.aoStrength = 2.4;
  }

  // Земля: комья, песчинки, вросшие камешки
  function genDirt(T, seed) {
    var S = T.S;
    for (var y = 0; y < S; y++) for (var x = 0; x < S; x++) {
      var u = x / S, v = y / S;
      var lump = N.fbm(u * 9, v * 9, 9, seed, 5);
      var gran = N.fbm(u * 55, v * 55, 55, seed + 5, 3);
      var micro = N.vnoise(u * 150, v * 150, 150, seed + 11);
      var w = N.worley(u * 22, v * 22, 22, seed + 31, 1.0);
      var stone = ss(0.16, 0.02, w[0]);           // мелкие камешки
      var h = 0.35 + 0.35 * lump + 0.14 * gran + 0.06 * micro;
      var shade = 0.85 + 0.3 * (lump - 0.5) + 0.16 * (gran - 0.5);
      var r = 0.235 * shade, g = 0.163 * shade, b = 0.105 * shade;
      var rough = 0.93 - 0.05 * gran;
      if (stone > 0) {
        var sg = 0.30 + 0.2 * w[2];
        r = lerp(r, sg, stone); g = lerp(g, sg * 0.98, stone); b = lerp(b, sg * 0.92, stone);
        h += stone * 0.22; rough = lerp(rough, 0.66, stone);
      }
      // трещинки
      var cr = N.ridged(u * 7, v * 7, 7, seed + 71, 4);
      if (cr > 0.74) { var c = (cr - 0.74) * 4; r *= 1 - c * 0.55; g *= 1 - c * 0.55; b *= 1 - c * 0.55; h -= c * 0.22; }
      T.set(x, y, r, g, b, clamp(h, 0, 1), rough);
    }
    T.bump = 1.8; T.paraScale = 0.035; T.aoStrength = 3.2;
  }

  // Скала: крупные плиты, глубокие трещины, вкрапления, мох в углублениях
  function genRock(T, seed) {
    var S = T.S;
    for (var y = 0; y < S; y++) for (var x = 0; x < S; x++) {
      var u = x / S, v = y / S;
      var wc = N.warp(u * 3.2, v * 3.2, 3, seed + 3, 0.55);
      var w = N.worley(wc[0], wc[1], 3, seed, 0.95);
      var plate = ss(0.0, 0.10, w[1] - w[0]);       // 0 в швах между плитами
      var tint = w[2];
      var grain = N.fbm(u * 20, v * 20, 20, seed + 17, 5);
      var micro = N.fbm(u * 80, v * 80, 80, seed + 23, 4);
      var crack = N.ridged(u * 6, v * 6, 6, seed + 41, 5);
      var crackM = ss(0.68, 0.95, crack);
      var h = 0.30 + 0.45 * plate + 0.16 * (grain - 0.5) + 0.07 * (micro - 0.5) - crackM * 0.42;
      var base = 0.315 + 0.075 * (tint - 0.5) + 0.115 * (grain - 0.5) + 0.05 * (micro - 0.5);
      var r = base * 1.03, g = base * 1.0, b = base * 0.98;
      var rough = 0.86 - 0.10 * grain;
      // блёстки кварца
      var fleck = N.vnoise(u * 190, v * 190, 190, seed + 61);
      if (fleck > 0.90) {
        var fk = (fleck - 0.90) * 8;
        r = lerp(r, 0.72, fk); g = lerp(g, 0.74, fk); b = lerp(b, 0.78, fk);
        rough = lerp(rough, 0.32, fk);
      }
      // мох в низинах
      var mossN = N.fbm(u * 3.4, v * 3.4, 3, seed + 91, 4);
      var moss = clamp((mossN - 0.46) * 3.4, 0, 1) * clamp((0.62 - h) * 3.0, 0, 1);
      moss *= ss(0.3, 0.6, N.fbm(u * 12, v * 12, 12, seed + 93, 3) + 0.2);
      if (moss > 0) {
        r = lerp(r, 0.115, moss); g = lerp(g, 0.215, moss); b = lerp(b, 0.075, moss);
        rough = lerp(rough, 0.96, moss); h += moss * 0.05;
      }
      if (crackM > 0) { r *= 1 - crackM * 0.55; g *= 1 - crackM * 0.55; b *= 1 - crackM * 0.5; }
      T.set(x, y, r, g, b, clamp(h, 0, 1), rough);
    }
    T.bump = 2.4; T.paraScale = 0.07; T.aoStrength = 4.0;
  }

  // Булыжник: округлые камни в растворе
  function genCobble(T, seed) {
    var S = T.S;
    for (var y = 0; y < S; y++) for (var x = 0; x < S; x++) {
      var u = x / S, v = y / S;
      var wc = N.warp(u * 4, v * 4, 4, seed + 5, 0.35);
      var w = N.worley(wc[0], wc[1], 4, seed, 1.0);
      var d = w[1] - w[0];
      var dome = ss(0.0, 0.42, d);
      var grain = N.fbm(u * 30, v * 30, 30, seed + 13, 4);
      var micro = N.fbm(u * 85, v * 85, 85, seed + 43, 3);
      var h = 0.10 + 0.82 * Math.sin(Math.pow(dome, 0.85) * Math.PI * 0.5) + 0.07 * (grain - 0.5) + 0.03 * (micro - 0.5);
      var tint = w[2];
      var base = (0.30 + 0.15 * (tint - 0.5) + 0.10 * (grain - 0.5) + 0.04 * (micro - 0.5)) * (0.72 + 0.5 * dome);
      var r = base * (1 + (tint - 0.5) * 0.16), g = base * 0.99, b = base * (0.95 - (tint - 0.5) * 0.12);
      var rough = 0.80 - 0.08 * grain;
      var fleck = N.vnoise(u * 200, v * 200, 200, seed + 71);
      if (fleck > 0.92) { r += 0.16; g += 0.17; b += 0.19; rough = 0.35; }
      // раствор между камнями
      var mortar = 1 - ss(0.02, 0.13, d);
      if (mortar > 0) {
        r = lerp(r, 0.27, mortar); g = lerp(g, 0.255, mortar); b = lerp(b, 0.23, mortar);
        rough = lerp(rough, 0.97, mortar);
      }
      var moss = clamp((N.fbm(u * 4, v * 4, 4, seed + 51, 4) - 0.5) * 3, 0, 1) * mortar;
      if (moss > 0) { r = lerp(r, 0.12, moss * 0.8); g = lerp(g, 0.22, moss * 0.8); b = lerp(b, 0.08, moss * 0.8); }
      T.set(x, y, r, g, b, clamp(h, 0, 1), rough);
    }
    T.bump = 2.6; T.paraScale = 0.085; T.aoStrength = 4.2;
  }

  // Песок: рябь и мелкое зерно
  function genSand(T, seed) {
    var S = T.S;
    for (var y = 0; y < S; y++) for (var x = 0; x < S; x++) {
      var u = x / S, v = y / S;
      var wv = N.fbm(u * 3, v * 3, 3, seed, 3);
      var ripple = 0.5 + 0.5 * Math.sin((v * 9 + u * 1.5 + wv * 1.6) * Math.PI * 2);
      ripple = Math.pow(ripple, 1.4);
      var gran = N.vnoise(u * 190, v * 190, 190, seed + 7);
      var gran2 = N.fbm(u * 70, v * 70, 70, seed + 3, 3);
      var h = 0.38 + 0.22 * ripple + 0.20 * gran2 + 0.14 * gran;
      var shade = 0.90 + 0.16 * (ripple - 0.5) + 0.12 * (gran - 0.5);
      var r = 0.62 * shade, g = 0.545 * shade, b = 0.395 * shade;
      var rough = 0.90 - 0.1 * gran;
      if (gran > 0.93) { r += 0.15; g += 0.15; b += 0.13; rough = 0.4; }
      T.set(x, y, r, g, b, clamp(h, 0, 1), rough);
    }
    T.bump = 1.2; T.paraScale = 0.02; T.aoStrength = 1.6;
  }

  // Доски: волокно, сучки, щели, гвозди
  function genPlanks(T, seed) {
    var S = T.S, rows = 4, ph = S / rows;
    for (var y = 0; y < S; y++) for (var x = 0; x < S; x++) {
      var u = x / S, v = y / S;
      var row = Math.floor(y / ph);
      var inRow = (y - row * ph) / ph;
      var off = N.hash2(row, 3, seed) * 10;
      var grain = N.fbm((u + off) * 4, v * 46, 4, seed + row * 31, 5);
      var fine = N.fbm((u + off) * 8, v * 130, 8, seed + 77, 3);
      var tone = 0.5 + 0.5 * Math.sin((grain * 7 + fine * 2.5) * Math.PI * 2);
      var warmth = N.hash2(row, 9, seed);
      var r = lerp(0.40, 0.55, tone) * lerp(0.85, 1.06, warmth);
      var g = lerp(0.26, 0.37, tone) * lerp(0.88, 1.04, warmth);
      var b = lerp(0.145, 0.21, tone);
      var h = 0.55 + 0.22 * tone + 0.1 * fine;
      var rough = 0.68 + 0.16 * tone;
      // сучок
      var kx = N.hash2(row, 17, seed), kv = N.hash2(row, 19, seed);
      if (kv > 0.42) {
        var dx = (u - kx) * S / ph, dy = (inRow - 0.5) * 2;
        var kd = Math.sqrt(dx * dx * 0.35 + dy * dy);
        if (kd < 1.05) {
          var kf = 1 - kd / 1.05;
          var rings = 0.5 + 0.5 * Math.sin(kd * 26 + grain * 3);
          r = lerp(r, 0.20 + 0.10 * rings, kf * 0.92);
          g = lerp(g, 0.115 + 0.06 * rings, kf * 0.92);
          b = lerp(b, 0.06 + 0.03 * rings, kf * 0.92);
          h -= kf * 0.10;
        }
      }
      // щель между досками
      var gap = Math.min(inRow, 1 - inRow);
      var gm = 1 - ss(0.0, 0.055, gap);
      if (gm > 0) { r *= 1 - gm * 0.72; g *= 1 - gm * 0.72; b *= 1 - gm * 0.72; h -= gm * 0.5; rough = 0.95; }
      T.set(x, y, r, g, b, clamp(h, 0, 1), rough);
    }
    // гвозди
    for (var ry = 0; ry < rows; ry++) for (var nx = 0; nx < 2; nx++) {
      var px = Math.round((0.12 + nx * 0.76) * S), py = Math.round((ry + 0.5) * ph);
      for (var dy2 = -2; dy2 <= 2; dy2++) for (var dx2 = -2; dx2 <= 2; dx2++) {
        var dd = Math.sqrt(dx2 * dx2 + dy2 * dy2);
        if (dd > 2.1) continue;
        var f = 1 - dd / 2.1;
        T.blend(px + dx2, py + dy2, 0.30 - dy2 * 0.02, 0.29, 0.28, 0.45, 0.35, f * 0.9);
      }
    }
    T.bump = 1.5; T.paraScale = 0.03; T.aoStrength = 2.6;
  }

  // Кора дерева: вертикальные гребни и глубокие борозды
  function genBark(T, seed) {
    var S = T.S;
    for (var y = 0; y < S; y++) for (var x = 0; x < S; x++) {
      var u = x / S, v = y / S;
      var wc = N.warp(u * 8, v * 2.2, 8, seed + 2, 0.30);
      var ridge = N.ridged(wc[0], wc[1] * 0.55, 8, seed, 5, 0.55);
      var fine = N.fbm(u * 42, v * 12, 42, seed + 13, 4);
      var deep = N.fbm(u * 5, v * 1.6, 5, seed + 29, 3);
      var h = 0.22 + 0.60 * ridge + 0.14 * fine + 0.12 * deep;
      var sh = 0.65 + 0.75 * ridge + 0.22 * (fine - 0.5);
      var r = 0.175 * sh, g = 0.128 * sh, b = 0.088 * sh;
      var rough = 0.94 - 0.08 * ridge;
      var moss = clamp((N.fbm(u * 3, v * 3, 3, seed + 61, 4) - 0.52) * 4, 0, 1) * clamp((0.55 - h) * 3, 0, 1);
      if (moss > 0) { r = lerp(r, 0.10, moss); g = lerp(g, 0.20, moss); b = lerp(b, 0.06, moss); }
      T.set(x, y, r, g, b, clamp(h, 0, 1), rough);
    }
    T.bump = 2.8; T.paraScale = 0.09; T.aoStrength = 4.5;
  }

  // Срез бревна: годовые кольца
  function genLogTop(T, seed) {
    var S = T.S;
    for (var y = 0; y < S; y++) for (var x = 0; x < S; x++) {
      var u = x / S - 0.5, v = y / S - 0.5;
      var d = Math.sqrt(u * u + v * v);
      var ang = Math.atan2(v, u);
      var wob = N.fbm(u * 6 + 3, v * 6 + 3, 6, seed, 4) * 0.06;
      var rings = 0.5 + 0.5 * Math.sin((d + wob) * 95 + Math.sin(ang * 3) * 0.4);
      var fine = N.fbm(u * 40, v * 40, 40, seed + 5, 3);
      var r = lerp(0.44, 0.60, rings) + 0.05 * (fine - 0.5);
      var g = lerp(0.30, 0.42, rings) + 0.04 * (fine - 0.5);
      var b = lerp(0.17, 0.24, rings);
      var h = 0.55 + 0.2 * rings + 0.1 * fine;
      // кора по краю
      var barkM = ss(0.40, 0.47, d);
      if (barkM > 0) {
        var ridge = N.ridged(ang * 4.5, d * 30, 8, seed + 7, 4);
        r = lerp(r, 0.16 * (0.7 + ridge), barkM); g = lerp(g, 0.115 * (0.7 + ridge), barkM); b = lerp(b, 0.08 * (0.7 + ridge), barkM);
        h = lerp(h, 0.3 + 0.4 * ridge, barkM);
      }
      T.set(x, y, r, g, b, clamp(h, 0, 1), 0.78);
    }
    T.bump = 1.3; T.aoStrength = 2.2;
  }

  // Листва: отдельные листики с прожилками и дырами (alpha)
  function genLeaves(T, seed, dense) {
    var S = T.S, rnd = N.mulberry32(seed);
    for (var i = 0; i < S * S; i++) { T.al[i] = 0; T.h[i] = 0; T.rg[i] = 0.85; }
    var count = Math.round(S * (dense ? 1.5 : 0.95));
    for (var k = 0; k < count; k++) {
      var cx = rnd() * S, cy = rnd() * S;
      var rad = (dense ? 0.075 : 0.072) * S * (0.55 + rnd() * rnd() * 1.5);
      var ang = rnd() * Math.PI * 2;
      var ca = Math.cos(ang), sa = Math.sin(ang);
      var tone = rnd();
      var patch = N.fbm(cx / S * 3, cy / S * 3, 3, seed + 5, 3);
      var lr = lerp(0.075, 0.24, tone * 0.6 + patch * 0.4);
      var lg = lerp(0.24, 0.50, tone * 0.5 + patch * 0.5);
      var lb = lerp(0.05, 0.14, tone);
      if (rnd() < 0.10) { lr = 0.42; lg = 0.34; lb = 0.08; }  // осенний лист
      var R = Math.ceil(rad * 1.6);
      for (var dy = -R; dy <= R; dy++) for (var dx = -R; dx <= R; dx++) {
        var lx = (dx * ca + dy * sa) / rad, ly = (-dx * sa + dy * ca) / (rad * 0.42);
        var e = lx * lx + ly * ly;
        // форма листа: эллипс с заострением
        var shape = e + Math.abs(lx) * 0.42 - 0.10;
        if (shape > 1) continue;
        var f = 1 - shape;
        var vein = Math.abs(ly) < 0.12 ? 1 : (Math.abs(Math.sin(lx * 9)) < 0.13 && Math.abs(ly) < 0.8 ? 0.6 : 0);
        var shd = 0.72 + 0.5 * f + 0.18 * (N.vnoise(cx + dx, cy + dy, 9999, seed + 3) - 0.5);
        var rr = lr * shd, gg = lg * shd, bb = lb * shd;
        if (vein > 0) { rr = lerp(rr, lr * 1.6, vein * 0.55); gg = lerp(gg, lg * 1.45, vein * 0.55); bb = lerp(bb, lb * 1.3, vein * 0.55); }
        var hh = 0.35 + 0.55 * f + (vein > 0 ? 0.1 : 0);
        var px = Math.round(cx + dx), py = Math.round(cy + dy);
        var idx = (((py % S) + S) % S) * S + (((px % S) + S) % S);
        if (T.al[idx] > 0.5 && T.h[idx] > hh) continue;
        T.set(px, py, rr, gg, bb, hh, 0.86, 1);
      }
    }
    // мягкие края альфы
    T.bump = 1.9; T.aoStrength = 2.0; T.alphaTest = true;
  }

  // Кирпич
  function genBrick(T, seed) {
    var S = T.S, rows = 5, bw = S / 2.5;
    for (var y = 0; y < S; y++) for (var x = 0; x < S; x++) {
      var u = x / S, v = y / S;
      var rowF = v * rows, row = Math.floor(rowF), inRow = rowF - row;
      var shift = (row % 2) * 0.5;
      var colF = u * 2.5 + shift, col = Math.floor(colF), inCol = colF - col;
      var mortarY = 1 - ss(0.0, 0.09, Math.min(inRow, 1 - inRow));
      var mortarX = 1 - ss(0.0, 0.045, Math.min(inCol, 1 - inCol));
      var m = Math.max(mortarX, mortarY);
      var id = N.hash2(col, row, seed);
      var grain = N.fbm(u * 40, v * 40, 40, seed + row * 7 + col * 13, 4);
      var blotch = N.fbm(u * 12, v * 12, 12, seed + 3, 4);
      var r = lerp(0.20, 0.34, id) * (0.80 + 0.40 * grain) * (0.86 + 0.30 * blotch);
      var g = lerp(0.098, 0.150, id) * (0.82 + 0.36 * grain) * (0.92 + 0.18 * blotch);
      var b = lerp(0.080, 0.118, id) * (0.82 + 0.36 * grain);
      var h = 0.72 + 0.12 * grain;
      var rough = 0.86;
      // сколотые края
      var chip = N.fbm(u * 26, v * 26, 26, seed + 91, 3);
      var edge = Math.max(1 - ss(0.0, 0.13, Math.min(inCol, 1 - inCol)), 1 - ss(0.0, 0.2, Math.min(inRow, 1 - inRow)));
      if (edge > 0.4 && chip > 0.62) { h -= 0.22 * (chip - 0.62) * 5 * edge; r *= 0.9; g *= 0.9; b *= 0.9; }
      if (m > 0) {
        var mg = 0.40 + 0.16 * (N.fbm(u * 70, v * 70, 70, seed + 17, 3) - 0.5);
        r = lerp(r, mg, m); g = lerp(g, mg * 0.98, m); b = lerp(b, mg * 0.94, m);
        h = lerp(h, 0.30 + 0.08 * grain, m); rough = 0.96;
      }
      T.set(x, y, r, g, b, clamp(h, 0, 1), rough);
    }
    T.bump = 2.2; T.paraScale = 0.06; T.aoStrength = 3.6;
  }

  // Гравий / щебень
  function genGravel(T, seed) {
    var S = T.S;
    for (var y = 0; y < S; y++) for (var x = 0; x < S; x++) {
      var u = x / S, v = y / S;
      var wc = N.warp(u * 7, v * 7, 7, seed + 61, 0.5);
      var w1 = N.worley(wc[0], wc[1], 7, seed, 1.0);
      var w2 = N.worley(u * 13, v * 13, 13, seed + 131, 1.0);
      var best, bestId, gap;
      if (w1[0] * 1.0 < w2[0] * 1.35) { best = w1[0]; bestId = w1[2]; gap = w1[1] - w1[0]; }
      else { best = w2[0] * 1.35; bestId = w2[2]; gap = (w2[1] - w2[0]) * 1.35; }
      var dome = ss(0.0, 0.30, gap);
      var h = 0.10 + 0.85 * Math.sin(Math.pow(dome, 0.8) * Math.PI * 0.5);
      var grain = N.fbm(u * 55, v * 55, 55, seed + 5, 4);
      h += 0.06 * (grain - 0.5);
      var base = lerp(0.20, 0.50, bestId) * (0.82 + 0.36 * grain);
      var tintR = 1 + (bestId - 0.5) * 0.30, tintB = 1 - (bestId - 0.5) * 0.26;
      var r = base * tintR, g = base * (1 - (bestId - 0.5) * 0.05), b = base * tintB;
      var rough = 0.88 - 0.20 * bestId;
      var deep = 1 - dome;
      r = lerp(r, 0.085, deep * 0.85); g = lerp(g, 0.075, deep * 0.85); b = lerp(b, 0.065, deep * 0.85);
      T.set(x, y, r, g, b, clamp(h, 0, 1), rough);
    }
    T.bump = 2.6; T.paraScale = 0.075; T.aoStrength = 4.2;
  }

  // Черепица
  function genRoof(T, seed) {
    var S = T.S, rows = 5;
    for (var y = 0; y < S; y++) for (var x = 0; x < S; x++) {
      var u = x / S, v = y / S;
      var rowF = v * rows, row = Math.floor(rowF), inRow = rowF - row;
      var shift = (row % 2) * 0.5;
      var colF = u * 4 + shift, col = Math.floor(colF), inCol = colF - col;
      var dome = Math.sin(inCol * Math.PI) * (1 - inRow * 0.35);
      var id = N.hash2(col, row, seed);
      var grain = N.fbm(u * 40, v * 40, 40, seed + col * 3 + row * 5, 4);
      var h = 0.25 + 0.6 * dome + 0.1 * grain - ss(0.75, 1.0, inRow) * 0.35;
      var base = lerp(0.16, 0.30, id) * (0.72 + 0.5 * dome) * (0.88 + 0.24 * grain);
      var r = base * 1.35, g = base * 0.70, b = base * 0.52;
      var moss = clamp((N.fbm(u * 5, v * 5, 5, seed + 31, 4) - 0.55) * 4, 0, 1);
      if (moss > 0) { r = lerp(r, 0.13, moss); g = lerp(g, 0.21, moss); b = lerp(b, 0.09, moss); }
      T.set(x, y, r, g, b, clamp(h, 0, 1), 0.82 - 0.1 * dome);
    }
    T.bump = 2.2; T.paraScale = 0.05; T.aoStrength = 3.4;
  }

  // Живая изгородь — очень плотная листва (стены лабиринта)
  function genHedge(T, seed) {
    genLeaves(T, seed, true);
    var S = T.S;
    // заполняем дыры тёмной внутренностью куста
    for (var y = 0; y < S; y++) for (var x = 0; x < S; x++) {
      var i = y * S + x;
      if (T.al[i] < 0.5) {
        var u = x / S, v = y / S;
        var d = N.fbm(u * 20, v * 20, 20, seed + 3, 4);
        var sh = 0.35 + 0.4 * d;
        T.set(x, y, 0.035 * sh, 0.075 * sh, 0.028 * sh, 0.05 + 0.1 * d, 0.95, 1);
      }
    }
    T.alphaTest = false;
    T.bump = 2.4; T.aoStrength = 4.5; T.paraScale = 0.05;
  }

  // Плитняк — пол дорожек лабиринта
  function genFlagstone(T, seed) {
    var S = T.S;
    for (var y = 0; y < S; y++) for (var x = 0; x < S; x++) {
      var u = x / S, v = y / S;
      var wc = N.warp(u * 2.6, v * 2.6, 3, seed + 9, 0.4);
      var w = N.worley(wc[0], wc[1], 3, seed, 0.85);
      var d = w[1] - w[0];
      var gapM = 1 - ss(0.015, 0.10, d);
      var grain = N.fbm(u * 26, v * 26, 26, seed + 11, 5);
      var micro = N.fbm(u * 90, v * 90, 90, seed + 13, 3);
      var wear = N.fbm(u * 6, v * 6, 6, seed + 17, 4);
      var id = w[2];
      var base = 0.36 + 0.09 * (id - 0.5) + 0.10 * (grain - 0.5) + 0.05 * (micro - 0.5);
      base *= 0.9 + 0.22 * wear;
      var r = base * 1.02, g = base, b = base * 0.95;
      var h = 0.72 + 0.14 * (grain - 0.5) + 0.06 * (micro - 0.5) - gapM * 0.55;
      var rough = 0.80 - 0.15 * wear;
      if (gapM > 0) {
        var dirt = 0.16 + 0.09 * N.fbm(u * 40, v * 40, 40, seed + 23, 3);
        r = lerp(r, dirt * 1.2, gapM); g = lerp(g, dirt, gapM); b = lerp(b, dirt * 0.8, gapM);
        rough = lerp(rough, 0.97, gapM);
      }
      T.set(x, y, r, g, b, clamp(h, 0, 1), rough);
    }
    T.bump = 2.0; T.paraScale = 0.05; T.aoStrength = 3.6;
  }

  // Грязь / влажная земля у воды
  function genMud(T, seed) {
    genDirt(T, seed + 55);
    var S = T.S;
    for (var y = 0; y < S; y++) for (var x = 0; x < S; x++) {
      var i = y * S + x, j = i * 3;
      var u = x / S, v = y / S;
      var wet = ss(0.35, 0.75, N.fbm(u * 5, v * 5, 5, seed + 3, 4));
      T.alb[j] *= lerp(1, 0.55, wet); T.alb[j + 1] *= lerp(1, 0.55, wet); T.alb[j + 2] *= lerp(1, 0.6, wet);
      T.rg[i] = lerp(T.rg[i], 0.28, wet);
    }
    T.bump = 1.6; T.paraScale = 0.03;
  }

  // Высокая трава: пучок вертикальных травинок на прозрачном фоне
  function genTallGrass(T, seed) {
    var S = T.S, rnd = N.mulberry32(seed);
    for (var i = 0; i < S * S; i++) { T.al[i] = 0; T.h[i] = 0.5; T.rg[i] = 0.8; }
    var blades = Math.round(S * 0.55);
    for (var b = 0; b < blades; b++) {
      var bx = (0.08 + rnd() * 0.84) * S;
      var by = 0;
      var len = (0.45 + rnd() * rnd() * 0.5) * S;
      var lean = (rnd() - 0.5) * 0.55;
      var w0 = 1.1 + rnd() * 2.2;
      var tone = rnd();
      var baseR = lerp(0.055, 0.20, tone), baseG = lerp(0.20, 0.46, tone), baseB = lerp(0.03, 0.10, tone);
      var steps = Math.round(len);
      for (var s = 0; s < steps; s++) {
        var f = s / steps;
        var px = bx + lean * len * f * f;
        var py = by + s;
        var w = Math.max(0.7, w0 * (1 - f * 0.85));
        var tip = f * f;
        var r = baseR * (1 + tip * 0.85) + tip * 0.07;
        var g = baseG * (1 + tip * 0.62) + tip * 0.11;
        var bl = baseB * (1 + tip * 0.45);
        for (var o = -w; o <= w; o += 0.5) {
          var edge = 1 - Math.abs(o) / (w + 0.4);
          var shd = 0.62 + 0.5 * edge;
          T.set(Math.round(px + o), Math.round(py), r * shd, g * shd, bl * shd, 0.5 + 0.3 * f, 0.78, 1);
        }
      }
    }
    T.bump = 1.2; T.aoStrength = 1.2;
  }

  // Плющ: свисающие плети с листьями, много прозрачности
  function genVine(T, seed) {
    var S = T.S, rnd = N.mulberry32(seed);
    for (var i = 0; i < S * S; i++) { T.al[i] = 0; T.h[i] = 0.4; T.rg[i] = 0.86; }
    function leaf(cx, cy, rad, ang, tone) {
      var ca = Math.cos(ang), sa = Math.sin(ang);
      var R = Math.ceil(rad * 1.7);
      for (var dy = -R; dy <= R; dy++) for (var dx = -R; dx <= R; dx++) {
        var lx = (dx * ca + dy * sa) / rad, ly = (-dx * sa + dy * ca) / (rad * 0.55);
        var e = lx * lx + ly * ly + Math.abs(lx) * 0.35 - 0.12;
        if (e > 1) continue;
        var f = 1 - e;
        var vein = Math.abs(ly) < 0.16 ? 0.5 : 0;
        var shd = 0.68 + 0.55 * f;
        var r = lerp(0.06, 0.22, tone) * shd, g = lerp(0.22, 0.48, tone) * shd, b = lerp(0.04, 0.11, tone) * shd;
        if (vein > 0) { r *= 1.35; g *= 1.28; b *= 1.2; }
        var px = Math.round(cx + dx), py = Math.round(cy + dy);
        T.set(px, py, r, g, b, 0.35 + 0.5 * f, 0.85, 1);
      }
    }
    // 4-6 плетей сверху вниз
    var strands = 4 + Math.floor(rnd() * 3);
    for (var s2 = 0; s2 < strands; s2++) {
      var x = (0.08 + rnd() * 0.84) * S;
      var len = (0.45 + rnd() * 0.55) * S;
      var drift = (rnd() - 0.5) * 0.5;
      var tone = rnd();
      for (var y = S - 1; y > S - 1 - len; y--) {
        var f = (S - 1 - y) / len;
        var cx = x + Math.sin(f * 5.5 + s2) * S * 0.045 + drift * f * S * 0.12;
        // стебель
        for (var w = -1; w <= 1; w++) {
          T.set(Math.round(cx + w), y, 0.09 + 0.05 * tone, 0.17 + 0.07 * tone, 0.05, 0.5, 0.9, 1);
        }
        // листья через интервал
        if ((S - 1 - y) % Math.round(6 + rnd() * 6) === 0) {
          var side = rnd() < 0.5 ? -1 : 1;
          leaf(cx + side * S * 0.035, y, S * (0.030 + rnd() * 0.03), side * (0.5 + rnd() * 0.9), tone * 0.7 + rnd() * 0.3);
        }
      }
    }
    T.bump = 1.6; T.aoStrength = 1.6;
  }

  // Куст: плотная округлая масса листвы на прозрачном фоне
  function genBush(T, seed) {
    genLeaves(T, seed + 17, true);
    var S = T.S;
    for (var y = 0; y < S; y++) for (var x = 0; x < S; x++) {
      var u = x / S - 0.5, v = y / S - 0.5;
      var d = Math.sqrt(u * u + v * v * 1.15);
      var edge = N.fbm(x / S * 7, y / S * 7, 7, seed + 5, 4) * 0.13;
      if (d + edge > 0.47) T.al[y * S + x] = 0;
    }
    T.bump = 2.0; T.aoStrength = 2.5;
  }

  // ---------------------------------------------------------------
  //  Постобработка тайла: нормали (Sobel), AO (по высоте)
  // ---------------------------------------------------------------
  function computeNormalAO(T) {
    var S = T.S, h = T.h;
    var nx = new Float32Array(S * S), ny = new Float32Array(S * S), nz = new Float32Array(S * S);
    function H(x, y) { return h[(((y % S) + S) % S) * S + (((x % S) + S) % S)]; }
    var st = T.bump * 3.2;
    for (var y = 0; y < S; y++) for (var x = 0; x < S; x++) {
      var tl = H(x - 1, y - 1), t = H(x, y - 1), tr = H(x + 1, y - 1);
      var l = H(x - 1, y), r = H(x + 1, y);
      var bl = H(x - 1, y + 1), b = H(x, y + 1), br = H(x + 1, y + 1);
      var dX = (tr + 2 * r + br) - (tl + 2 * l + bl);
      var dY = (bl + 2 * b + br) - (tl + 2 * t + tr);
      var vx = -dX * st, vy = -dY * st, vz = 1.0;
      var len = Math.sqrt(vx * vx + vy * vy + vz * vz);
      var i = y * S + x;
      nx[i] = vx / len; ny[i] = vy / len; nz[i] = vz / len;
    }
    // AO: сравнение с размытой высотой (2 прохода box-blur)
    var rad = Math.max(2, Math.round(S * 0.045));
    var tmp = new Float32Array(S * S), blur = new Float32Array(S * S);
    for (var y2 = 0; y2 < S; y2++) for (var x2 = 0; x2 < S; x2++) {
      var s = 0;
      for (var k = -rad; k <= rad; k++) s += H(x2 + k, y2);
      tmp[y2 * S + x2] = s / (rad * 2 + 1);
    }
    function Tv(x, y) { return tmp[(((y % S) + S) % S) * S + (((x % S) + S) % S)]; }
    for (var y3 = 0; y3 < S; y3++) for (var x3 = 0; x3 < S; x3++) {
      var s2 = 0;
      for (var k2 = -rad; k2 <= rad; k2++) s2 += Tv(x3, y3 + k2);
      blur[y3 * S + x3] = s2 / (rad * 2 + 1);
    }
    var ao = new Float32Array(S * S);
    for (var i2 = 0; i2 < S * S; i2++) {
      ao[i2] = clamp(1 - (blur[i2] - h[i2]) * T.aoStrength, 0.25, 1);
    }
    return { nx: nx, ny: ny, nz: nz, ao: ao };
  }

  // box-фильтр одного тайла с заворотом (для честных мип-уровней)
  function downsample(src, w, h, ch) {
    var nw = w >> 1, nh = h >> 1;
    var out = new Float32Array(nw * nh * ch);
    for (var y = 0; y < nh; y++) for (var x = 0; x < nw; x++) {
      for (var c = 0; c < ch; c++) {
        var s = src[((y * 2) * w + x * 2) * ch + c] + src[((y * 2) * w + x * 2 + 1) * ch + c] +
          src[((y * 2 + 1) * w + x * 2) * ch + c] + src[((y * 2 + 1) * w + x * 2 + 1) * ch + c];
        out[(y * nw + x) * ch + c] = s * 0.25;
      }
    }
    return out;
  }

  // ---------------------------------------------------------------
  //  Сборка текстурных массивов
  // ---------------------------------------------------------------
  var GENS = [
    ['GRASS_TOP', genGrassTop], ['GRASS_SIDE', genGrassSide], ['DIRT', genDirt],
    ['ROCK', genRock], ['COBBLE', genCobble], ['SAND', genSand],
    ['PLANKS', genPlanks], ['BARK', genBark], ['LOG_TOP', genLogTop],
    ['LEAVES', function (T, s) { genLeaves(T, s, false); }], ['BRICK', genBrick], ['GRAVEL', genGravel],
    ['ROOF', genRoof], ['HEDGE', genHedge], ['FLAGSTONE', genFlagstone], ['MUD', genMud],
    ['TALL_GRASS', genTallGrass], ['BUSH', genBush], ['VINE', genVine]
  ];

  // Пошаговый сборщик — чтобы показывать прогресс загрузки без фризов
  function createBuilder(THREE, S) {
    var layers = GENS.length;
    var mipCount = Math.round(Math.log(S) / Math.LN2) - 1;   // до 4x4
    var albMips = [], nrmMips = [], matMips = [];
    for (var m = 0; m < mipCount; m++) {
      var sz = S >> m;
      albMips.push(new Uint8Array(sz * sz * layers * 4));
      nrmMips.push(new Uint8Array(sz * sz * layers * 4));
      matMips.push(new Uint8Array(sz * sz * layers * 4));
    }
    var paraScales = new Float32Array(layers);

    function genLayer(li) {
      var T = new Tile(S);
      GENS[li][1](T, 1000 + li * 137);
      var na = computeNormalAO(T);
      paraScales[li] = T.paraScale;
      var albF = new Float32Array(S * S * 4), nrmF = new Float32Array(S * S * 4), matF = new Float32Array(S * S * 4);
      for (var i = 0; i < S * S; i++) {
        albF[i * 4] = T.alb[i * 3]; albF[i * 4 + 1] = T.alb[i * 3 + 1]; albF[i * 4 + 2] = T.alb[i * 3 + 2]; albF[i * 4 + 3] = T.al[i];
        nrmF[i * 4] = na.nx[i] * 0.5 + 0.5; nrmF[i * 4 + 1] = na.ny[i] * 0.5 + 0.5; nrmF[i * 4 + 2] = na.nz[i] * 0.5 + 0.5; nrmF[i * 4 + 3] = 1;
        matF[i * 4] = T.rg[i]; matF[i * 4 + 1] = T.h[i]; matF[i * 4 + 2] = na.ao[i]; matF[i * 4 + 3] = 1;
      }
      var a = albF, n = nrmF, mt = matF, sz2 = S;
      for (var mi = 0; mi < mipCount; mi++) {
        var base = sz2 * sz2 * li * 4;
        var cnt = sz2 * sz2 * 4;
        for (var p = 0; p < cnt; p++) {
          var isA = (p & 3) === 3;
          albMips[mi][base + p] = clamp(isA ? a[p] : Math.pow(clamp(a[p], 0, 1), 1 / 2.2), 0, 1) * 255;
          nrmMips[mi][base + p] = clamp(n[p], 0, 1) * 255;
          matMips[mi][base + p] = clamp(mt[p], 0, 1) * 255;
        }
        if (mi < mipCount - 1) {
          a = downsample(a, sz2, sz2, 4); n = downsample(n, sz2, sz2, 4); mt = downsample(mt, sz2, sz2, 4);
          sz2 >>= 1;
        }
      }
    }

    function makeArrayTex(THREE, mips) {
      var t = new THREE.DataArrayTexture(mips[0], S, S, layers);
      t.format = THREE.RGBAFormat; t.type = THREE.UnsignedByteType;
      t.magFilter = THREE.LinearFilter; t.minFilter = THREE.LinearMipmapLinearFilter;
      t.wrapS = t.wrapT = THREE.RepeatWrapping;
      t.generateMipmaps = true;
      t.anisotropy = 8;
      t.needsUpdate = true;
      return t;
    }

    return {
      total: layers, done: 0,
      step: function () { if (this.done < layers) { genLayer(this.done); this.done++; } },
      finish: function () {
        return {
          albedo: makeArrayTex(THREE, albMips),
          normal: makeArrayTex(THREE, nrmMips),
          matmap: makeArrayTex(THREE, matMips),
          paraScales: paraScales, TILES: TILES, count: layers, size: S
        };
      }
    };
  }

  function buildTextures(THREE, S, onProgress) {
    var b = createBuilder(THREE, S);
    while (b.done < b.total) { b.step(); if (onProgress) onProgress(b.done / b.total); }
    return b.finish();
  }

  // ---------------------------------------------------------------
  //  Отдельные 2D-текстуры (детализация, персонажи, вода)
  // ---------------------------------------------------------------
  function make2D(THREE, S, fill, srgb) {
    var data = new Uint8Array(S * S * 4);
    fill(data, S);
    var t = new THREE.DataTexture(data, S, S);
    t.format = THREE.RGBAFormat; t.type = THREE.UnsignedByteType;
    t.magFilter = THREE.LinearFilter; t.minFilter = THREE.LinearMipmapLinearFilter;
    t.wrapS = t.wrapT = THREE.RepeatWrapping;
    t.generateMipmaps = true; t.anisotropy = 4; t.needsUpdate = true;
    return t;
  }

  // Микродеталь: тонкая карта нормалей поверх всего (шумовое зерно)
  function detailNormal(THREE, S) {
    return make2D(THREE, S, function (d, S) {
      var h = new Float32Array(S * S);
      for (var y = 0; y < S; y++) for (var x = 0; x < S; x++) {
        h[y * S + x] = N.fbm(x / S * 26, y / S * 26, 26, 4242, 5, 0.55) * 0.7 +
          N.vnoise(x / S * 90, y / S * 90, 90, 77) * 0.3;
      }
      function H(x, y) { return h[(((y % S) + S) % S) * S + (((x % S) + S) % S)]; }
      for (var y2 = 0; y2 < S; y2++) for (var x2 = 0; x2 < S; x2++) {
        var dX = H(x2 + 1, y2) - H(x2 - 1, y2), dY = H(x2, y2 + 1) - H(x2, y2 - 1);
        var vx = -dX * 6, vy = -dY * 6, vz = 1;
        var l = Math.sqrt(vx * vx + vy * vy + 1);
        var i = (y2 * S + x2) * 4;
        d[i] = (vx / l * 0.5 + 0.5) * 255; d[i + 1] = (vy / l * 0.5 + 0.5) * 255;
        d[i + 2] = (vz / l * 0.5 + 0.5) * 255; d[i + 3] = H(x2, y2) * 255;
      }
    });
  }

  // Волны на воде: две смешанные карты нормалей
  function waterNormal(THREE, S) {
    return make2D(THREE, S, function (d, S) {
      var h = new Float32Array(S * S);
      for (var y = 0; y < S; y++) for (var x = 0; x < S; x++) {
        var u = x / S, v = y / S;
        h[y * S + x] = N.fbm(u * 5, v * 5, 5, 913, 4, 0.55) * 0.65 +
          N.fbm(u * 13, v * 13, 13, 55, 3, 0.5) * 0.35;
      }
      function H(x, y) { return h[(((y % S) + S) % S) * S + (((x % S) + S) % S)]; }
      for (var y2 = 0; y2 < S; y2++) for (var x2 = 0; x2 < S; x2++) {
        var dX = H(x2 + 1, y2) - H(x2 - 1, y2), dY = H(x2, y2 + 1) - H(x2, y2 - 1);
        var vx = -dX * 12, vy = -dY * 12;
        var l = Math.sqrt(vx * vx + vy * vy + 1);
        var i = (y2 * S + x2) * 4;
        d[i] = (vx / l * 0.5 + 0.5) * 255; d[i + 1] = (vy / l * 0.5 + 0.5) * 255;
        d[i + 2] = (1 / l * 0.5 + 0.5) * 255; d[i + 3] = 255;
      }
    });
  }

  global.TEX = {
    build: buildTextures, createBuilder: createBuilder, TILES: TILES, TILE_COUNT: TILE_COUNT,
    detailNormal: detailNormal, waterNormal: waterNormal, make2D: make2D, Tile: Tile,
    computeNormalAO: computeNormalAO
  };
})(window);
