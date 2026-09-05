/* =====================================================================
   water.js — вода: волны из двух слоёв нормалей, френель, отражение
   аналитического неба, блик солнца, пена у берега, глубинный цвет.
   ===================================================================== */
(function (global) {
  'use strict';

  function create(THREE, common, skyUniforms, size, level, opts) {
    opts = opts || {};
    var u = Object.assign({}, common);
    u.uWaterNormal = { value: opts.normalMap || null };
    u.uTop = skyUniforms.uTop;
    u.uHor = skyUniforms.uHor;
    u.uSunCol = skyUniforms.uSunCol;
    u.uSunPos = skyUniforms.uSunPos;
    u.uDeep = { value: new THREE.Color(0.012, 0.055, 0.075) };
    u.uShallow = { value: new THREE.Color(0.06, 0.20, 0.20) };
    u.uLevel = { value: level };

    var mat = new THREE.ShaderMaterial({
      glslVersion: THREE.GLSL3,
      uniforms: u,
      transparent: true,
      side: THREE.DoubleSide,
      vertexShader: [
        'precision highp float;',
        'out vec3 vWorld; out vec2 vUv2;',
        'uniform float uTime;',
        'void main(){',
        '  vec4 wp = modelMatrix * vec4(position, 1.0);',
        // крупная зыбь геометрией
        '  wp.y += sin(wp.x * 0.09 + uTime * 0.55) * 0.055 + sin(wp.z * 0.13 - uTime * 0.42) * 0.045;',
        '  vWorld = wp.xyz; vUv2 = wp.xz;',
        '  gl_Position = projectionMatrix * viewMatrix * wp;',
        '}'
      ].join('\n'),
      fragmentShader: [
        'precision highp float;',
        'in vec3 vWorld; in vec2 vUv2;',
        'out vec4 outColor;',
        'uniform sampler2D uWaterNormal;',
        'uniform vec3 uTop, uHor, uSunCol, uSunPos, uDeep, uShallow;',
        'uniform float uLevel;',
        global.GFX.LIB,
        'vec3 skyColor(vec3 dir){',
        '  float up = clamp(dir.y, 0.0, 1.0);',
        '  vec3 sky = mix(uHor, uTop, pow(up, 0.42));',
        '  float mu = dot(dir, uSunPos);',
        '  float g = 0.76;',
        '  float mie = (1.0 - g*g) / pow(1.0 + g*g - 2.0*g*mu, 1.5);',
        '  sky += uSunCol * mie * 0.03;',
        '  sky += uSunCol * pow(max(mu, 0.0), 20.0) * 0.35;',
        '  return sky;',
        '}',
        'void main(){',
        '  vec3 V = normalize(uCamPos - vWorld);',
        '  float dist = length(uCamPos - vWorld);',
        // две прокручивающиеся карты нормалей разного масштаба
        '  vec2 uv1 = vUv2 * 0.085 + vec2(uTime * 0.020, uTime * 0.014);',
        '  vec2 uv2 = vUv2 * 0.185 - vec2(uTime * 0.031, uTime * 0.017);',
        '  vec2 uv3 = vUv2 * 0.420 + vec2(uTime * 0.048, -uTime * 0.038);',
        '  vec3 n1 = texture(uWaterNormal, uv1).xyz * 2.0 - 1.0;',
        '  vec3 n2 = texture(uWaterNormal, uv2).xyz * 2.0 - 1.0;',
        '  vec3 n3 = texture(uWaterNormal, uv3).xyz * 2.0 - 1.0;',
        '  float far = clamp(1.0 - dist / 90.0, 0.15, 1.0);',
        '  vec3 nTS = normalize(n1 * 0.55 + n2 * 0.32 * far + n3 * 0.22 * far * far);',
        '  vec3 N = normalize(vec3(nTS.x * 0.85, 1.0, nTS.y * 0.85));',
        '  float ndv = max(dot(N, V), 1e-3);',
        '  float fres = pow(1.0 - ndv, 5.0) * 0.92 + 0.045;',
        '  vec3 R = reflect(-V, N);',
        '  R.y = abs(R.y);',
        '  vec3 refl = skyColor(R);',
        // солнечный блик
        '  vec3 H = normalize(uSunDir + V);',
        '  float spec = pow(max(dot(N, H), 0.0), 220.0);',
        '  refl += uSunColor * spec * 6.0;',
        // цвет толщи
        '  vec3 deep = mix(uShallow, uDeep, clamp(dist / 55.0, 0.0, 1.0));',
        '  deep *= skyAmbient(vec3(0.0, 1.0, 0.0)) * 1.6;',
        '  vec3 col = mix(deep, refl, clamp(fres, 0.0, 1.0));',
        // пена по гребням волн
        '  float crest = smoothstep(0.55, 0.95, fbm2(vUv2 * 0.55 + vec2(uTime * 0.05, 0.0)));',
        '  col += vec3(0.55) * crest * 0.10;',
        '  col = applyFog(col, vWorld);',
        '  float alpha = mix(0.86, 1.0, clamp(fres * 2.0, 0.0, 1.0));',
        '  outColor = vec4(col, alpha);',
        '}'
      ].join('\n')
    });

    var geo = new THREE.PlaneGeometry(size, size, 48, 48).rotateX(-Math.PI / 2);
    var mesh = new THREE.Mesh(geo, mat);
    mesh.position.y = level;
    mesh.renderOrder = 5;
    return { mesh: mesh, material: mat, uniforms: u };
  }

  global.WATER = { create: create };
})(window);
