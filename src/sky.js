/* =====================================================================
   sky.js — атмосферное небо: градиент рассеяния, солнце с ореолом,
   многослойные объёмные облака, луна с кратерами, звёзды и Млечный Путь.
   Всё аналитическое, ни одной картинки.
   ===================================================================== */
(function (global) {
  'use strict';

  // Палитры времени суток. Ключ — высота солнца (sin угла).
  var PALETTES = [
    { // глубокая ночь
      key: -0.55,
      top: [0.010, 0.017, 0.048], hor: [0.045, 0.055, 0.105],
      sun: [0.42, 0.50, 0.78], amb: 0.62, ground: [0.026, 0.030, 0.044],
      fog: [0.050, 0.062, 0.112], fogSun: [0.12, 0.14, 0.26], stars: 1.0,
      cloudLit: [0.30, 0.34, 0.48], cloudDark: [0.055, 0.065, 0.105], exposure: 1.70
    },
    { // сумерки
      key: -0.08,
      top: [0.075, 0.10, 0.26], hor: [0.62, 0.30, 0.32],
      sun: [1.35, 0.52, 0.30], amb: 0.75, ground: [0.09, 0.07, 0.09],
      fog: [0.42, 0.26, 0.32], fogSun: [1.10, 0.45, 0.28], stars: 0.55,
      cloudLit: [1.25, 0.62, 0.48], cloudDark: [0.22, 0.16, 0.28], exposure: 1.35
    },
    { // закат / рассвет
      key: 0.09,
      top: [0.16, 0.28, 0.62], hor: [1.05, 0.62, 0.38],
      sun: [2.20, 1.20, 0.62], amb: 1.0, ground: [0.16, 0.13, 0.11],
      fog: [0.80, 0.58, 0.46], fogSun: [1.60, 0.90, 0.50], stars: 0.10,
      cloudLit: [1.70, 1.10, 0.80], cloudDark: [0.34, 0.28, 0.36], exposure: 1.20
    },
    { // золотой час
      key: 0.30,
      top: [0.20, 0.40, 0.80], hor: [0.86, 0.80, 0.66],
      sun: [2.30, 1.85, 1.30], amb: 1.15, ground: [0.20, 0.17, 0.13],
      fog: [0.78, 0.76, 0.70], fogSun: [1.55, 1.20, 0.80], stars: 0.0,
      cloudLit: [1.62, 1.44, 1.22], cloudDark: [0.38, 0.39, 0.48], exposure: 1.12
    },
    { // день
      key: 0.85,
      top: [0.19, 0.40, 0.86], hor: [0.68, 0.80, 0.95],
      sun: [2.25, 2.10, 1.90], amb: 1.25, ground: [0.22, 0.20, 0.16],
      fog: [0.68, 0.78, 0.93], fogSun: [1.20, 1.15, 1.00], stars: 0.0,
      cloudLit: [1.60, 1.62, 1.66], cloudDark: [0.42, 0.47, 0.60], exposure: 1.05
    }
  ];

  function lerpArr(a, b, t) { return [a[0] + (b[0] - a[0]) * t, a[1] + (b[1] - a[1]) * t, a[2] + (b[2] - a[2]) * t]; }

  function samplePalette(h) {
    var i = 0;
    for (; i < PALETTES.length - 1; i++) if (h < PALETTES[i + 1].key) break;
    var a = PALETTES[Math.min(i, PALETTES.length - 1)], b = PALETTES[Math.min(i + 1, PALETTES.length - 1)];
    var t = a === b ? 0 : Math.max(0, Math.min(1, (h - a.key) / (b.key - a.key)));
    t = t * t * (3 - 2 * t);
    return {
      top: lerpArr(a.top, b.top, t), hor: lerpArr(a.hor, b.hor, t),
      sun: lerpArr(a.sun, b.sun, t), ground: lerpArr(a.ground, b.ground, t),
      fog: lerpArr(a.fog, b.fog, t), fogSun: lerpArr(a.fogSun, b.fogSun, t),
      cloudLit: lerpArr(a.cloudLit, b.cloudLit, t), cloudDark: lerpArr(a.cloudDark, b.cloudDark, t),
      amb: a.amb + (b.amb - a.amb) * t,
      stars: a.stars + (b.stars - a.stars) * t,
      exposure: a.exposure + (b.exposure - a.exposure) * t
    };
  }

  var VERT = [
    'out vec3 vDir;',
    'void main(){',
    '  vDir = position;',
    '  vec4 mv = modelViewMatrix * vec4(position, 1.0);',
    '  gl_Position = projectionMatrix * mv;',
    '}'
  ].join('\n');

  var FRAG = [
    'precision highp float;',
    'in vec3 vDir;',
    'out vec4 outColor;',
    'uniform vec3 uSunDir, uMoonDir, uSunPos;',
    'uniform vec3 uTop, uHor, uSunCol, uCloudLit, uCloudDark;',
    'uniform float uTime, uStars, uCloudCover, uSunSize;',
    '',
    'float hash12(vec2 p){ vec3 p3 = fract(vec3(p.xyx) * 0.1031); p3 += dot(p3, p3.yzx + 33.33); return fract((p3.x + p3.y) * p3.z); }',
    'float hash13(vec3 p3){ p3 = fract(p3 * 0.1031); p3 += dot(p3, p3.zyx + 31.32); return fract((p3.x + p3.y) * p3.z); }',
    'float vnoise(vec2 p){ vec2 i=floor(p), f=fract(p); f=f*f*(3.-2.*f);',
    '  return mix(mix(hash12(i),hash12(i+vec2(1,0)),f.x), mix(hash12(i+vec2(0,1)),hash12(i+vec2(1,1)),f.x), f.y); }',
    'float fbm(vec2 p, int oct){ float s=0., a=.5, n=0.; for(int i=0;i<7;i++){ if(i>=oct) break; s+=a*vnoise(p); n+=a; p=p*2.02+vec2(1.7,9.2); a*=.5; } return s/n; }',
    '',
    // плотность облаков на слое
    'float cloudDensity(vec2 p, float cover, float sharp, int oct){',
    '  vec2 w = vec2(fbm(p*0.55+vec2(uTime*0.006,0.0),3), fbm(p*0.55+vec2(5.2,1.3),3)) - 0.5;',
    '  float d = fbm(p + w*1.35, oct);',
    '  d = smoothstep(cover, cover + sharp, d);',
    '  return d;',
    '}',
    '',
    'vec4 cloudLayer(vec3 dir, float height, float scale, float cover, float sharp, float speed, int oct){',
    '  if (dir.y <= 0.012) return vec4(0.0);',
    '  float t = height / dir.y;',
    '  vec2 p = dir.xz * t * scale + vec2(uTime * speed, uTime * speed * 0.35);',
    '  float d = cloudDensity(p, cover, sharp, oct);',
    '  if (d <= 0.001) return vec4(0.0);',
    // освещение: сравниваем плотность со смещением к солнцу
    '  vec2 sp = normalize(uSunDir.xz + vec2(0.001)) * 0.55;',
    '  float d1 = cloudDensity(p + sp, cover, sharp, oct);',
    '  float d2 = cloudDensity(p + sp * 2.2, cover, sharp, oct);',
    '  float shadow = clamp(1.0 - (d1 * 0.55 + d2 * 0.45) * 0.95, 0.0, 1.0);',
    '  float rim = clamp(d - d1, 0.0, 1.0);',
    '  vec3 col = mix(uCloudDark, uCloudLit, shadow * shadow);',
    '  col += uSunCol * rim * 1.35;',                     // подсветка кромки
    '  float sunAmt = max(dot(dir, uSunDir), 0.0);',
    '  col += uSunCol * pow(sunAmt, 7.0) * 0.45 * (1.0 - shadow);', // просвет
    '  float fade = smoothstep(0.012, 0.10, dir.y);',
    '  return vec4(col, d * fade);',
    '}',
    '',
    'void main(){',
    '  vec3 dir = normalize(vDir);',
    '  float h = dir.y;',
    // базовый градиент неба
    '  float up = clamp(h, 0.0, 1.0);',
    '  vec3 sky = mix(uHor, uTop, pow(up, 0.42));',
    // ниже горизонта — плавно в цвет земли
    '  sky = mix(sky, uHor * 0.55, smoothstep(0.0, -0.22, h));',
    // рассеяние Ми вокруг солнца
    '  float mu = dot(dir, uSunDir);',
    '  float g = 0.76;',
    '  float mie = (1.0 - g*g) / pow(1.0 + g*g - 2.0*g*mu, 1.5);',
    '  sky += uSunCol * mie * 0.028;',
    '  sky += uSunCol * pow(max(mu, 0.0), 12.0) * 0.30;',
    // засветка у горизонта со стороны солнца
    '  float horGlow = exp(-abs(h) * 9.0) * pow(max(mu, 0.0) * 0.5 + 0.5, 3.0);',
    '  sky += uSunCol * horGlow * 0.22;',
    '',
    // звёзды
    '  if (uStars > 0.001) {',
    '    vec3 sd = dir * 130.0;',
    '    vec3 cell = floor(sd);',
    '    float st = hash13(cell);',
    '    if (st > 0.9915) {',
    '      vec3 fp = fract(sd) - 0.5;',
    '      float d = length(fp);',
    '      float bright = (st - 0.9915) / 0.0085;',
    '      float tw = 0.55 + 0.45 * sin(uTime * (1.4 + bright * 5.0) + hash13(cell + 3.3) * 20.0);',
    '      vec3 sc = mix(vec3(0.75,0.85,1.0), vec3(1.0,0.85,0.65), hash13(cell + 7.7));',
    '      sky += sc * smoothstep(0.36, 0.0, d) * bright * tw * 2.6 * uStars * smoothstep(-0.02, 0.15, h);',
    '    }',
    // Млечный Путь
    '    float band = exp(-pow((dir.y - dir.x * 0.35) * 2.4, 2.0));',
    '    float mw = fbm(vec2(atan(dir.z, dir.x) * 2.2, dir.y * 3.5), 5);',
    '    sky += vec3(0.42, 0.46, 0.72) * band * mw * mw * 0.16 * uStars * smoothstep(0.0, 0.2, h);',
    '  }',
    '',
    // луна
    '  float md = dot(dir, uMoonDir);',
    '  if (md > 0.9975) {',
    '    vec2 mp = (dir - uMoonDir * md).xz * 260.0;',
    '    float cr = fbm(mp * 2.4 + 11.0, 5);',
    '    float e = smoothstep(0.9975, 0.9990, md);',
    '    vec3 mc = vec3(0.95, 0.94, 0.88) * (0.72 + 0.55 * cr);',
    '    sky = mix(sky, mc * 2.2, e * uStars);',
    '  }',
    '  sky += vec3(0.55,0.60,0.75) * pow(max(md,0.0), 260.0) * 0.9 * uStars;',
    '',
    // солнце: диск + ореол
    '  float smu = dot(dir, uSunPos);',
    '  float sunDisc = smoothstep(uSunSize, uSunSize * 0.55, acos(clamp(smu, -1.0, 1.0)));',
    '  float above = smoothstep(-0.09, 0.02, uSunPos.y);',
    '  sky += uSunCol * sunDisc * 9.0 * above;',
    '  sky += uSunCol * pow(max(smu,0.0), 900.0) * 3.5 * above;',
    '',
    // облака: три слоя с параллаксом
    '  vec4 c1 = cloudLayer(dir, 1.0, 1.30, uCloudCover + 0.10, 0.34, 0.010, 6);',
    '  vec4 c2 = cloudLayer(dir, 2.1, 0.62, uCloudCover + 0.02, 0.30, 0.016, 6);',
    '  vec4 c3 = cloudLayer(dir, 4.4, 0.34, uCloudCover + 0.22, 0.26, 0.026, 5);',
    '  vec3 col = sky;',
    '  col = mix(col, c3.rgb, c3.a * 0.75);',
    '  col = mix(col, c2.rgb, c2.a * 0.92);',
    '  col = mix(col, c1.rgb, c1.a);',
    '',
    '  outColor = vec4(col, 1.0);',
    '}'
  ].join('\n');

  function create(THREE, common) {
    var uniforms = {
      uSunDir: common.uSunDir,
      uMoonDir: { value: new THREE.Vector3(0, -1, 0) },
      uSunPos: { value: new THREE.Vector3(0, 1, 0) },
      uTop: { value: new THREE.Color(0.2, 0.4, 0.85) },
      uHor: { value: new THREE.Color(0.7, 0.82, 0.95) },
      uSunCol: { value: new THREE.Color(2.2, 2.0, 1.8) },
      uCloudLit: { value: new THREE.Color(1.9, 1.9, 1.9) },
      uCloudDark: { value: new THREE.Color(0.45, 0.5, 0.6) },
      uTime: common.uTime,
      uStars: { value: 0 },
      uCloudCover: { value: 0.42 },
      uSunSize: { value: 0.028 }
    };
    var mat = new THREE.ShaderMaterial({
      glslVersion: THREE.GLSL3, uniforms: uniforms,
      vertexShader: VERT, fragmentShader: FRAG,
      side: THREE.BackSide, depthWrite: false, depthTest: false, fog: false
    });
    var mesh = new THREE.Mesh(new THREE.SphereGeometry(1, 32, 24), mat);
    mesh.frustumCulled = false;
    mesh.renderOrder = -1000;
    mesh.scale.setScalar(900);
    return { mesh: mesh, uniforms: uniforms, material: mat };
  }

  global.SKY = { create: create, samplePalette: samplePalette, PALETTES: PALETTES };
})(window);
