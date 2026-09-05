/* =====================================================================
   particles.js — пылинки и пыльца в воздухе (ночью — светлячки).
   Один вызов отрисовки: THREE.Points с собственным шейдером.
   ===================================================================== */
(function (global) {
  'use strict';

  function createMotes(THREE, common, count, box) {
    count = count || 380;
    box = box || 26;
    var pos = new Float32Array(count * 3);
    var seed = new Float32Array(count);
    for (var i = 0; i < count; i++) {
      pos[i * 3] = (Math.random() - 0.5) * box;
      pos[i * 3 + 1] = (Math.random() - 0.5) * box * 0.55;
      pos[i * 3 + 2] = (Math.random() - 0.5) * box;
      seed[i] = Math.random();
    }
    var geo = new THREE.BufferGeometry();
    geo.setAttribute('position', new THREE.Float32BufferAttribute(pos, 3));
    geo.setAttribute('aSeed', new THREE.Float32BufferAttribute(seed, 1));
    geo.boundingSphere = new THREE.Sphere(new THREE.Vector3(), 1e6);

    var u = Object.assign({}, common);
    u.uBox = { value: box };
    u.uPixel = { value: 600 };
    u.uNight = { value: 0 };

    var mat = new THREE.ShaderMaterial({
      glslVersion: THREE.GLSL3,
      uniforms: u,
      transparent: true, depthWrite: false, blending: THREE.AdditiveBlending,
      vertexShader: [
        'precision highp float;',
        'in float aSeed;',
        'uniform vec3 uCamPos, uSunDir;',
        'uniform float uTime, uBox, uPixel, uNight;',
        'out float vFade; out float vSeed;',
        'void main(){',
        '  vec3 p = position;',
        // медленный дрейф
        '  float t = uTime * (0.25 + aSeed * 0.35);',
        '  p.x += sin(t + aSeed * 31.0) * 1.4;',
        '  p.y += sin(t * 0.7 + aSeed * 17.0) * 0.9 + uTime * (0.05 + aSeed * 0.06);',
        '  p.z += cos(t * 0.8 + aSeed * 11.0) * 1.4;',
        // «бесконечное поле»: заворачиваем вокруг камеры
        '  vec3 rel = p - uCamPos;',
        '  vec3 half3 = vec3(uBox * 0.5, uBox * 0.28, uBox * 0.5);',
        '  rel = mod(rel + half3, half3 * 2.0) - half3;',
        '  vec3 wpos = uCamPos + rel;',
        '  vec4 mv = viewMatrix * vec4(wpos, 1.0);',
        '  float dist = -mv.z;',
        '  float sz = mix(0.020, 0.055, aSeed) * (1.0 + uNight * 1.4);',
        '  gl_PointSize = clamp(uPixel * sz / max(dist, 0.2), 1.0, 22.0);',
        '  gl_Position = projectionMatrix * mv;',
        // гаснут вблизи и вдали
        '  vFade = smoothstep(0.35, 1.6, dist) * (1.0 - smoothstep(uBox * 0.30, uBox * 0.52, dist));',
        '  vSeed = aSeed;',
        '}'
      ].join('\n'),
      fragmentShader: [
        'precision highp float;',
        'in float vFade; in float vSeed;',
        'out vec4 outColor;',
        'uniform vec3 uSunColor, uSkyHor;',
        'uniform float uTime, uNight;',
        'void main(){',
        '  vec2 c = gl_PointCoord - 0.5;',
        '  float d = length(c);',
        '  float a = smoothstep(0.5, 0.06, d);',
        '  if (a <= 0.001) discard;',
        '  float tw = 0.55 + 0.45 * sin(uTime * (1.5 + vSeed * 4.0) + vSeed * 30.0);',
        '  vec3 col = mix(uSunColor * 0.55 + uSkyHor * 0.35, vec3(1.1, 0.95, 0.35), uNight);',
        '  float amt = mix(0.45, 0.90, uNight) * tw;',
        '  outColor = vec4(col * amt, a * vFade * mix(0.55, 0.95, uNight));',
        '}'
      ].join('\n')
    });

    var pts = new THREE.Points(geo, mat);
    pts.frustumCulled = false;
    pts.renderOrder = 20;
    pts.userData.isMotes = true;
    return { points: pts, material: mat, uniforms: u };
  }

  global.PARTICLES = { createMotes: createMotes };
})(window);
