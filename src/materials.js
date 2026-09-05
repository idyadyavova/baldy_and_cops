/* =====================================================================
   materials.js — шейдеры поверхностей.
   Блоки: parallax occlusion mapping + карта нормалей + микродеталь.
   Листва: alpha-test + ветер. Объекты: обычный PBR с картами.
   ===================================================================== */
(function (global) {
  'use strict';

  var POM_FN = [
    'vec2 parallax(vec2 uv, vec3 vts, float scale, float layer){',
    '  float nSteps = mix(28.0, 10.0, clamp(abs(vts.z), 0.0, 1.0));',
    '  float dz = 1.0 / nSteps;',
    '  vec2 dUV = (vts.xy / max(abs(vts.z), 0.35)) * scale * dz;',
    '  float cur = 0.0;',
    '  vec2 p = uv;',
    '  float h = 1.0 - textureLod(uMatMap, vec3(p, layer), 0.0).g;',
    '  for (int i = 0; i < 28; i++) {',
    '    if (cur >= h) break;',
    '    p -= dUV; cur += dz;',
    '    h = 1.0 - textureLod(uMatMap, vec3(p, layer), 0.0).g;',
    '  }',
    '  vec2 prev = p + dUV;',
    '  float after = h - cur;',
    '  float before = (1.0 - textureLod(uMatMap, vec3(prev, layer), 0.0).g) - cur + dz;',
    '  float w = clamp(after / max(after - before, 1e-4), 0.0, 1.0);',
    '  return mix(p, prev, w);',
    '}'
  ].join('\n');

  var TBN_FN = [
    'mat3 cotangentFrame(vec3 Ng, vec3 p, vec2 uv){',
    '  vec3 dp1 = dFdx(p), dp2 = dFdy(p);',
    '  vec2 duv1 = dFdx(uv), duv2 = dFdy(uv);',
    '  vec3 dp2perp = cross(dp2, Ng), dp1perp = cross(Ng, dp1);',
    '  vec3 T = dp2perp * duv1.x + dp1perp * duv2.x;',
    '  vec3 Bv = dp2perp * duv1.y + dp1perp * duv2.y;',
    '  float invmax = inversesqrt(max(dot(T,T), dot(Bv,Bv)));',
    '  return mat3(T * invmax, Bv * invmax, Ng);',
    '}'
  ].join('\n');

  var WIND_FN = [
    'vec3 windOffset(vec3 wpos, float amount){',
    '  if (amount <= 0.001) return vec3(0.0);',
    '  float t = uTime * 1.35;',
    '  float gust = 0.55 + 0.45 * sin(t * 0.23 + wpos.x * 0.035 + wpos.z * 0.028);',
    '  float s1 = sin(t + wpos.x * 0.55 + wpos.z * 0.35);',
    '  float s2 = sin(t * 1.9 + wpos.x * 1.30 - wpos.z * 0.70);',
    '  float sway = (s1 * 0.62 + s2 * 0.38) * gust;',
    '  return vec3(sway * 0.085, -abs(sway) * 0.018, sway * 0.055) * amount * uWind;',
    '}'
  ].join('\n');

  function blockVertex(withWind) {
    return [
      'precision highp float;',
      'in float aTile; in float aAO; in float aWind;',
      'out vec3 vWorld; out vec3 vNormal; out vec2 vUv; out float vAO;',
      'flat out float vTile;',
      'uniform float uTime, uWind;',
      WIND_FN,
      'void main(){',
      '  vec4 wp = modelMatrix * vec4(position, 1.0);',
      withWind ? '  wp.xyz += windOffset(wp.xyz, aWind);' : '',
      '  vWorld = wp.xyz;',
      '  vNormal = normalize(mat3(modelMatrix) * normal);',
      '  vUv = uv; vTile = aTile; vAO = aAO;',
      '  gl_Position = projectionMatrix * viewMatrix * wp;',
      '}'
    ].join('\n');
  }

  function blockFragment(opts) {
    var foliage = opts.foliage;
    return [
      'precision highp float;',
      'precision highp sampler2DArray;',
      'in vec3 vWorld; in vec3 vNormal; in vec2 vUv; in float vAO;',
      'flat in float vTile;',
      'out vec4 outColor;',
      'uniform sampler2DArray uAlbedo, uNormalMap, uMatMap;',
      'uniform float uParaScale[24];',
      'uniform float uPOM, uDetailAmt, uDebug;',
      global.GFX.LIB,
      TBN_FN,
      POM_FN,
      'void main(){',
      '  float layer = vTile;',
      '  vec3 Ng = normalize(vNormal);',
      foliage ? '  if (!gl_FrontFacing) Ng = -Ng;' : '',
      '  vec3 V = normalize(uCamPos - vWorld);',
      '  float dist = length(uCamPos - vWorld);',
      '  mat3 TBN = cotangentFrame(Ng, -V * dist, vUv);',
      '  vec2 uvp = vUv;',
      !foliage ? [
        '  float pscale = uParaScale[int(layer)] * uPOM * clamp(1.6 - dist / 14.0, 0.0, 1.0);',
        '  if (pscale > 0.002) {',
        '    vec3 vts = normalize(vec3(dot(V, TBN[0]), dot(V, TBN[1]), dot(V, TBN[2])));',
        '    uvp = parallax(vUv, vts, pscale, layer);',
        '  }'
      ].join('\n') : '',
      '  vec4 alb = texture(uAlbedo, vec3(uvp, layer));',
      foliage ? '  if (alb.a < 0.5) discard;' : '',
      '  vec3 albedo = alb.rgb * alb.rgb * (0.6 + 0.4 * alb.rgb);',   // приближение sRGB->linear
      '  vec3 nTS = texture(uNormalMap, vec3(uvp, layer)).xyz * 2.0 - 1.0;',
      '  vec4 mm = texture(uMatMap, vec3(uvp, layer));',
      // микродеталь вблизи
      '  float dAmt = uDetailAmt * clamp(1.0 - dist / 11.0, 0.0, 1.0);',
      '  if (dAmt > 0.01) {',
      '    vec3 dn = texture(uDetail, uvp * 3.0 + vec2(layer * 0.37)).xyz * 2.0 - 1.0;',
      '    nTS.xy += dn.xy * dAmt * 0.55;',
      '  }',
      '  vec3 Nw = normalize(TBN * normalize(nTS));',
      '  float rough = clamp(mm.r, 0.05, 1.0);',
      '  float ao = mm.b * vAO;',
      foliage ? '  ao = mix(ao, 1.0, 0.35);' : '',
      '  if (uDebug > 0.5) {',
      '    vec3 dbg = vec3(0.0);',
      '    if (uDebug < 1.5) dbg = vec3(shadowAt(vWorld, Nw, max(dot(Nw, uSunDir), 0.0)));',
      '    else if (uDebug < 2.5) dbg = Nw * 0.5 + 0.5;',
      '    else if (uDebug < 3.5) dbg = albedo;',
      '    else if (uDebug < 4.5) dbg = vec3(ao);',
      '    else if (uDebug < 5.5) dbg = vec3(max(dot(Nw, uSunDir), 0.0));',
      '    else {',
      '      vec4 sc = uShadowMat * vec4(vWorld, 1.0); vec3 pp = sc.xyz / sc.w;',
      '      float dd = texture(uShadowMap, pp.xy).r;',
      '      if (uDebug < 6.5) dbg = vec3(pp.xy, 0.0);',
      '      else if (uDebug < 7.5) dbg = vec3(fract(dd * 8.0), fract(dd * 64.0), dd);',
      '      else dbg = vec3(clamp((pp.z - dd) * 400.0, 0.0, 1.0), clamp((dd - pp.z) * 400.0, 0.0, 1.0), fract(pp.z * 8.0));',
      '    }',
      '    outColor = vec4(dbg, 1.0); return;',
      '  }',
      '  vec3 col = shade(albedo, Nw, vWorld, rough, 0.0, ao, 1.0);',
      foliage ? [
        // просвет листвы на просвет солнца
        '  float trans = pow(clamp(dot(-V, uSunDir), 0.0, 1.0), 3.0);',
        '  col += albedo * uSunColor * trans * 0.55 * shadowAt(vWorld, Nw, 1.0);'
      ].join('\n') : '',
      '  col = applyFog(col, vWorld);',
      '  outColor = vec4(col, 1.0);',
      '}'
    ].join('\n');
  }

  function blockUniforms(THREE, common, tex) {
    var para = new Float32Array(24);
    for (var i = 0; i < tex.paraScales.length && i < 24; i++) para[i] = tex.paraScales[i];
    var u = Object.assign({}, common);
    u.uAlbedo = { value: tex.albedo };
    u.uNormalMap = { value: tex.normal };
    u.uMatMap = { value: tex.matmap };
    u.uParaScale = { value: para };
    u.uPOM = { value: 1.0 };
    u.uDetailAmt = { value: 1.0 };
    u.uDebug = { value: 0.0 };
    return u;
  }

  function makeBlockMaterial(THREE, common, tex) {
    return new THREE.ShaderMaterial({
      glslVersion: THREE.GLSL3,
      uniforms: blockUniforms(THREE, common, tex),
      vertexShader: blockVertex(false),
      fragmentShader: blockFragment({ foliage: false })
    });
  }

  function makeFoliageMaterial(THREE, common, tex) {
    return new THREE.ShaderMaterial({
      glslVersion: THREE.GLSL3,
      uniforms: blockUniforms(THREE, common, tex),
      vertexShader: blockVertex(true),
      fragmentShader: blockFragment({ foliage: true }),
      side: THREE.DoubleSide
    });
  }

  // --- материал для прохода теней ---------------------------------
  function makeBlockDepthMaterial(THREE, common, tex, foliage) {
    var u = { uTime: common.uTime, uWind: common.uWind, uAlbedo: { value: tex.albedo } };
    return new THREE.ShaderMaterial({
      glslVersion: THREE.GLSL3,
      uniforms: u,
      vertexShader: [
        'in float aTile; in float aWind;',
        'out vec2 vUv; flat out float vTile;',
        'uniform float uTime, uWind;',
        WIND_FN,
        'void main(){',
        '  vec4 wp = modelMatrix * vec4(position, 1.0);',
        foliage ? '  wp.xyz += windOffset(wp.xyz, aWind);' : '',
        '  vUv = uv; vTile = aTile;',
        '  gl_Position = projectionMatrix * viewMatrix * wp;',
        '}'
      ].join('\n'),
      fragmentShader: [
        'precision highp float; precision highp sampler2DArray;',
        'uniform sampler2DArray uAlbedo;',
        'in vec2 vUv; flat in float vTile; out vec4 outColor;',
        'void main(){',
        foliage ? '  if (texture(uAlbedo, vec3(vUv, vTile)).a < 0.5) discard;' : '',
        '  outColor = vec4(1.0);',
        '}'
      ].join('\n'),
      side: foliage ? THREE.DoubleSide : THREE.FrontSide
    });
  }

  // --- обычный объектный материал (персонажи, декор) ---------------
  function makeObjectMaterial(THREE, common, opts) {
    opts = opts || {};
    var u = Object.assign({}, common);
    function srgb(hex) { var c = new THREE.Color(hex); c.r = Math.pow(c.r, 2.2); c.g = Math.pow(c.g, 2.2); c.b = Math.pow(c.b, 2.2); return c; }
    u.uColor = { value: srgb(opts.color !== undefined ? opts.color : 0xffffff) };
    u.uRough = { value: opts.rough !== undefined ? opts.rough : 0.7 };
    u.uMetal = { value: opts.metal !== undefined ? opts.metal : 0.0 };
    u.uEmissive = { value: srgb(opts.emissive || 0x000000) };
    u.uEmissiveAmt = { value: opts.emissiveAmt !== undefined ? opts.emissiveAmt : 0.0 };
    u.uMap = { value: opts.map || null };
    u.uNormalMapT = { value: opts.normalMap || null };
    u.uUseMap = { value: opts.map ? 1.0 : 0.0 };
    u.uUseNormal = { value: opts.normalMap ? 1.0 : 0.0 };
    u.uUvScale = { value: new THREE.Vector2(opts.uvScale ? opts.uvScale[0] : 1, opts.uvScale ? opts.uvScale[1] : 1) };
    u.uNoiseAmt = { value: opts.noiseAmt !== undefined ? opts.noiseAmt : 0.0 };
    u.uNoiseScale = { value: opts.noiseScale !== undefined ? opts.noiseScale : 12.0 };
    u.uSubsurface = { value: opts.subsurface !== undefined ? opts.subsurface : 0.0 };
    u.uRimCol = { value: srgb(opts.rim || 0x000000) };

    var mat = new THREE.ShaderMaterial({
      glslVersion: THREE.GLSL3,
      uniforms: u,
      vertexShader: [
        'precision highp float;',
        'out vec3 vWorld; out vec3 vNormal; out vec2 vUv; out vec3 vObj;',
        '#ifdef USE_SKINNING',
        '#endif',
        'void main(){',
        '  vec4 wp = modelMatrix * vec4(position, 1.0);',
        '  vWorld = wp.xyz;',
        '  vObj = position;',
        '  vNormal = normalize(mat3(modelMatrix) * normal);',
        '  vUv = uv;',
        '  gl_Position = projectionMatrix * viewMatrix * wp;',
        '}'
      ].join('\n'),
      fragmentShader: [
        'precision highp float;',
        'in vec3 vWorld; in vec3 vNormal; in vec2 vUv; in vec3 vObj;',
        'out vec4 outColor;',
        'uniform vec3 uColor, uEmissive, uRimCol;',
        'uniform float uRough, uMetal, uEmissiveAmt, uUseMap, uUseNormal, uNoiseAmt, uNoiseScale, uSubsurface;',
        'uniform sampler2D uMap, uNormalMapT;',
        'uniform vec2 uUvScale;',
        global.GFX.LIB,
        TBN_FN,
        'void main(){',
        '  vec3 Ng = normalize(vNormal);',
        '  if (!gl_FrontFacing) Ng = -Ng;',
        '  vec3 albedo = uColor;',
        '  float rough = uRough;',
        '  vec2 uvm = vUv * uUvScale;',
        '  if (uUseMap > 0.5) {',
        '    vec4 t = texture(uMap, uvm);',
        '    albedo *= t.rgb * t.rgb * (0.6 + 0.4 * t.rgb);',
        '  }',
        // процедурная неоднородность (ткань/кожа) без текстур
        '  if (uNoiseAmt > 0.001) {',
        '    float n = fbm2(vObj.xy * uNoiseScale) * 0.6 + fbm2(vObj.zy * uNoiseScale * 1.7) * 0.4;',
        '    albedo *= 1.0 + (n - 0.5) * uNoiseAmt;',
        '    rough = clamp(rough + (n - 0.5) * uNoiseAmt * 0.5, 0.05, 1.0);',
        '  }',
        '  vec3 Nw = Ng;',
        '  if (uUseNormal > 0.5) {',
        '    vec3 V0 = normalize(uCamPos - vWorld);',
        '    mat3 TBN = cotangentFrame(Ng, -V0 * length(uCamPos - vWorld), uvm);',
        '    vec3 nTS = texture(uNormalMapT, uvm).xyz * 2.0 - 1.0;',
        '    Nw = normalize(TBN * nTS);',
        '  }',
        '  vec3 col = shade(albedo, Nw, vWorld, rough, uMetal, 1.0, 1.0);',
        // подповерхностное рассеяние (кожа)
        '  if (uSubsurface > 0.001) {',
        '    vec3 V1 = normalize(uCamPos - vWorld);',
        '    float back = pow(clamp(dot(-V1, uSunDir) * 0.5 + 0.5, 0.0, 1.0), 2.5);',
        '    col += albedo * vec3(1.0, 0.42, 0.28) * back * uSubsurface * uSunColor * 0.35;',
        '  }',
        // ободковый свет от неба
        '  vec3 V2 = normalize(uCamPos - vWorld);',
        '  float rim = pow(1.0 - max(dot(Nw, V2), 0.0), 3.5);',
        '  col += uRimCol * rim;',
        '  col += uEmissive * uEmissiveAmt;',
        '  col = applyFog(col, vWorld);',
        '  outColor = vec4(col, 1.0);',
        '}'
      ].join('\n')
    });
    mat.userData.uniforms = u;
    return mat;
  }

  function makeObjectDepthMaterial(THREE) {
    return new THREE.ShaderMaterial({
      glslVersion: THREE.GLSL3,
      vertexShader: 'void main(){ gl_Position = projectionMatrix * viewMatrix * modelMatrix * vec4(position,1.0); }',
      fragmentShader: 'precision highp float; out vec4 o; void main(){ o = vec4(1.0); }'
    });
  }

  global.MAT = {
    makeBlockMaterial: makeBlockMaterial,
    makeFoliageMaterial: makeFoliageMaterial,
    makeBlockDepthMaterial: makeBlockDepthMaterial,
    makeObjectMaterial: makeObjectMaterial,
    makeObjectDepthMaterial: makeObjectDepthMaterial,
    TBN_FN: TBN_FN, WIND_FN: WIND_FN
  };
})(window);
