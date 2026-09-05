/* =====================================================================
   noise.js — процедурный шум для генерации текстур и мира.
   Всё периодическое (тайлится без швов), детерминированное по seed.
   ===================================================================== */
(function (global) {
  'use strict';

  // ---- детерминированный генератор случайных чисел -------------------
  function mulberry32(a) {
    return function () {
      a |= 0; a = (a + 0x6D2B79F5) | 0;
      var t = Math.imul(a ^ (a >>> 15), 1 | a);
      t = (t + Math.imul(t ^ (t >>> 7), 61 | t)) ^ t;
      return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
    };
  }

  // ---- хеши ----------------------------------------------------------
  function hash2(x, y, seed) {
    var h = Math.imul(x | 0, 374761393) ^ Math.imul(y | 0, 668265263) ^ Math.imul(seed | 0, 2246822519);
    h = Math.imul(h ^ (h >>> 13), 1274126177);
    return ((h ^ (h >>> 16)) >>> 0) / 4294967296;
  }
  function hash3(x, y, z, seed) {
    var h = Math.imul(x | 0, 374761393) ^ Math.imul(y | 0, 668265263) ^ Math.imul(z | 0, 2147483647) ^ Math.imul(seed | 0, 2246822519);
    h = Math.imul(h ^ (h >>> 13), 1274126177);
    return ((h ^ (h >>> 16)) >>> 0) / 4294967296;
  }

  function smooth(t) { return t * t * t * (t * (t * 6 - 15) + 10); }
  function lerp(a, b, t) { return a + (b - a) * t; }
  function wrap(v, p) { v %= p; return v < 0 ? v + p : v; }

  // ---- периодический value-noise 2D ----------------------------------
  function vnoise(x, y, per, seed) {
    var xi = Math.floor(x), yi = Math.floor(y);
    var xf = x - xi, yf = y - yi;
    var x0 = wrap(xi, per), x1 = wrap(xi + 1, per);
    var y0 = wrap(yi, per), y1 = wrap(yi + 1, per);
    var u = smooth(xf), v = smooth(yf);
    var a = hash2(x0, y0, seed), b = hash2(x1, y0, seed);
    var c = hash2(x0, y1, seed), d = hash2(x1, y1, seed);
    return lerp(lerp(a, b, u), lerp(c, d, u), v);
  }

  // ---- периодический fbm ---------------------------------------------
  function fbm(x, y, per, seed, oct, gain, lac) {
    oct = oct || 5; gain = gain === undefined ? 0.5 : gain; lac = lac || 2;
    var sum = 0, amp = 1, norm = 0, f = 1, p = per;
    for (var i = 0; i < oct; i++) {
      sum += amp * vnoise(x * f, y * f, Math.max(1, Math.round(p)), seed + i * 131);
      norm += amp; amp *= gain; f *= lac; p *= lac;
    }
    return sum / norm;
  }

  // ---- ridged fbm (гребни: трещины, кора, скалы) ----------------------
  function ridged(x, y, per, seed, oct, gain) {
    oct = oct || 4; gain = gain === undefined ? 0.5 : gain;
    var sum = 0, amp = 1, norm = 0, f = 1, p = per;
    for (var i = 0; i < oct; i++) {
      var n = vnoise(x * f, y * f, Math.max(1, Math.round(p)), seed + i * 977);
      n = 1 - Math.abs(n * 2 - 1);
      n *= n;
      sum += amp * n; norm += amp; amp *= gain; f *= 2; p *= 2;
    }
    return sum / norm;
  }

  // ---- периодический Worley (клетки: камни, галька, кладка) -----------
  // возвращает [f1, f2, cellHash]
  var _w = [0, 0, 0];
  function worley(x, y, per, seed, jitter) {
    jitter = jitter === undefined ? 1 : jitter;
    var xi = Math.floor(x), yi = Math.floor(y);
    var f1 = 1e9, f2 = 1e9, id = 0;
    for (var dy = -1; dy <= 1; dy++) {
      for (var dx = -1; dx <= 1; dx++) {
        var cx = xi + dx, cy = yi + dy;
        var wx = wrap(cx, per), wy = wrap(cy, per);
        var rx = hash2(wx, wy, seed), ry = hash2(wx, wy, seed + 7717);
        var px = cx + 0.5 + (rx - 0.5) * jitter;
        var py = cy + 0.5 + (ry - 0.5) * jitter;
        var ddx = px - x, ddy = py - y;
        var d = Math.sqrt(ddx * ddx + ddy * ddy);
        if (d < f1) { f2 = f1; f1 = d; id = hash2(wx, wy, seed + 313); }
        else if (d < f2) { f2 = d; }
      }
    }
    _w[0] = f1; _w[1] = f2; _w[2] = id;
    return _w;
  }

  // ---- домен-варп: искажение координат другим шумом -------------------
  function warp(x, y, per, seed, amount) {
    var wx = fbm(x, y, per, seed, 3) - 0.5;
    var wy = fbm(x + 5.2, y + 1.3, per, seed + 51, 3) - 0.5;
    return [x + wx * amount, y + wy * amount];
  }

  function clamp(v, a, b) { return v < a ? a : (v > b ? b : v); }
  function smoothstep(e0, e1, x) { var t = clamp((x - e0) / (e1 - e0), 0, 1); return t * t * (3 - 2 * t); }

  global.NZ = {
    mulberry32: mulberry32, hash2: hash2, hash3: hash3,
    vnoise: vnoise, fbm: fbm, ridged: ridged, worley: worley, warp: warp,
    lerp: lerp, clamp: clamp, smoothstep: smoothstep, smooth: smooth
  };
})(window);
