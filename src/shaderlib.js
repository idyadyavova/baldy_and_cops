/* =====================================================================
   shaderlib.js — общие uniform-ы и GLSL-функции освещения.
   Один направленный источник (солнце/луна), небесный ambient,
   мягкие тени PCF, точечные фонари, объёмный туман.
   ===================================================================== */
(function (global) {
  'use strict';

  var MAX_POINTS = 8;

  function makeCommon(THREE) {
    var pp = [], pc = [];
    for (var i = 0; i < MAX_POINTS; i++) { pp.push(new THREE.Vector4()); pc.push(new THREE.Vector4()); }
    return {
      uTime: { value: 0 },
      uCamPos: { value: new THREE.Vector3() },
      uSunDir: { value: new THREE.Vector3(0.4, 0.6, 0.3).normalize() },
      uSunColor: { value: new THREE.Color(1, 0.95, 0.85) },
      uSkyTop: { value: new THREE.Color(0.25, 0.45, 0.85) },
      uSkyHor: { value: new THREE.Color(0.75, 0.85, 1.0) },
      uGroundCol: { value: new THREE.Color(0.22, 0.20, 0.16) },
      uAmbient: { value: 1.0 },
      uFogCol: { value: new THREE.Color(0.7, 0.8, 0.95) },
      uFogSunCol: { value: new THREE.Color(1.0, 0.8, 0.55) },
      uFogDens: { value: 0.0042 },
      uFogHeight: { value: 0.075 },
      uShadowMap: { value: null },
      uShadowMat: { value: new THREE.Matrix4() },
      uShadowTexel: { value: 1 / 2048 },
      uShadowStrength: { value: 1.0 },
      uShadowBias: { value: 0.0005 },
      uShadowNormalOff: { value: 0.06 },
      uPointPos: { value: pp },
      uPointCol: { value: pc },
      uPointCount: { value: 0 },
      uDetail: { value: null },
      uWind: { value: 1.0 }
    };
  }

  // Общий GLSL-пролог для всех материалов сцены
  var LIB = [
    'uniform float uTime;',
    'uniform vec3 uCamPos, uSunDir;',
    'uniform vec3 uSunColor, uSkyTop, uSkyHor, uGroundCol;',
    'uniform float uAmbient;',
    'uniform vec3 uFogCol, uFogSunCol;',
    'uniform float uFogDens, uFogHeight;',
    'uniform sampler2D uShadowMap;',
    'uniform mat4 uShadowMat;',
    'uniform float uShadowTexel, uShadowStrength, uShadowBias, uShadowNormalOff;',
    'uniform vec4 uPointPos[' + MAX_POINTS + '];',
    'uniform vec4 uPointCol[' + MAX_POINTS + '];',
    'uniform int uPointCount;',
    'uniform sampler2D uDetail;',
    'uniform float uWind;',
    '',
    'const float PI = 3.14159265359;',
    '',
    'float hash12(vec2 p){ vec3 p3 = fract(vec3(p.xyx) * 0.1031); p3 += dot(p3, p3.yzx + 33.33); return fract((p3.x + p3.y) * p3.z); }',
    'float vnoise(vec2 p){ vec2 i=floor(p), f=fract(p); f=f*f*(3.-2.*f);',
    '  return mix(mix(hash12(i),hash12(i+vec2(1,0)),f.x), mix(hash12(i+vec2(0,1)),hash12(i+vec2(1,1)),f.x), f.y); }',
    'float fbm2(vec2 p){ float s=0., a=.5; for(int i=0;i<5;i++){ s+=a*vnoise(p); p*=2.03; a*=.5; } return s; }',
    '',
    // --- мягкая тень (PCF 3x3 + случайное вращение выборки) ------------
    'float shadowAt(vec3 wpos, vec3 N, float ndl){',
    '  wpos += N * uShadowNormalOff * (1.0 + 2.2 * (1.0 - ndl));',
    '  vec4 sc = uShadowMat * vec4(wpos, 1.0);',
    '  vec3 p = sc.xyz / sc.w;',
    '  if (p.x < 0.002 || p.x > 0.998 || p.y < 0.002 || p.y > 0.998 || p.z > 1.0) return 1.0;',
    '  float bias = uShadowBias * (1.0 + 2.0 * (1.0 - ndl));',
    '  float sum = 0.0;',
    '  float ang = hash12(floor(gl_FragCoord.xy)) * 6.2831;',
    '  vec2 rot = vec2(cos(ang), sin(ang));',
    '  for (int i = 0; i < 9; i++) {',
    '    vec2 o = vec2(float(i % 3) - 1.0, float(i / 3) - 1.0);',
    '    o = vec2(o.x * rot.x - o.y * rot.y, o.x * rot.y + o.y * rot.x) * uShadowTexel * 1.25;',
    '    float d = texture(uShadowMap, p.xy + o).r;',
    '    sum += step(p.z - bias, d);',
    '  }',
    '  float s = sum / 9.0;',
    '  return mix(1.0, s, uShadowStrength);',
    '}',
    '',
    // --- небесный ambient (полусфера) ----------------------------------
    'vec3 skyAmbient(vec3 N){',
    '  float up = N.y * 0.5 + 0.5;',
    '  vec3 sky = mix(uSkyHor, uSkyTop, smoothstep(0.35, 1.0, up));',
    '  return mix(uGroundCol, sky, smoothstep(0.0, 0.55, up)) * uAmbient;',
    '}',
    '',
    // --- GGX ------------------------------------------------------------
    'float D_GGX(float ndh, float a){ float a2=a*a; float d=ndh*ndh*(a2-1.)+1.; return a2/(PI*d*d+1e-6); }',
    'float V_Smith(float ndv, float ndl, float a){ float a2=a*a;',
    '  float gv = ndl*sqrt(ndv*ndv*(1.-a2)+a2); float gl = ndv*sqrt(ndl*ndl*(1.-a2)+a2);',
    '  return 0.5/max(gv+gl,1e-5); }',
    'vec3 F_Schlick(vec3 f0, float u){ float f=pow(1.-u,5.); return f0 + (1.-f0)*f; }',
    '',
    // --- основное затенение --------------------------------------------
    'vec3 shade(vec3 albedo, vec3 N, vec3 wpos, float rough, float metal, float ao, float extraShadow){',
    '  vec3 V = normalize(uCamPos - wpos);',
    '  vec3 L = uSunDir;',
    '  float ndl = dot(N, L);',
    '  float ndv = max(dot(N, V), 1e-4);',
    '  float sh = ndl > -0.05 ? shadowAt(wpos, N, max(ndl,0.0)) : 0.0;',
    '  sh *= extraShadow;',
    '  float a = max(rough * rough, 0.002);',
    '  vec3 f0 = mix(vec3(0.04), albedo, metal);',
    '  vec3 diffCol = albedo * (1.0 - metal);',
    // мягкая «обёртка» света — рассеяние под поверхностью
    '  float wrapped = clamp((ndl + 0.22) / 1.22, 0.0, 1.0);',
    '  vec3 direct = diffCol / PI * wrapped * sh;',
    '  vec3 H = normalize(L + V);',
    '  float ndh = max(dot(N, H), 0.0);',
    '  float vdh = max(dot(V, H), 0.0);',
    '  float spec = D_GGX(ndh, a) * V_Smith(ndv, max(ndl,0.0), a);',
    '  direct += F_Schlick(f0, vdh) * spec * max(ndl, 0.0) * sh;',
    '  direct *= uSunColor * PI;',
    // окружение: небо + отражение неба под углом Френеля
    '  vec3 amb = skyAmbient(N) * diffCol * ao;',
    '  vec3 R = reflect(-V, N);',
    '  float fres = pow(1.0 - ndv, 4.0) * (1.0 - rough) * 0.85;',
    '  amb += skyAmbient(R) * (F_Schlick(f0, ndv) * (0.25 + fres)) * ao;',
    // подсветка от земли (переотражение солнца)
    '  float bounce = clamp(-N.y * 0.5 + 0.5, 0.0, 1.0) * 0.45 + 0.18;',
    '  amb += uSunColor * uGroundCol * diffCol * bounce * ao;',
    '  vec3 col = direct + amb;',
    // точечные источники (фонари)
    '  for (int i = 0; i < ' + MAX_POINTS + '; i++) {',
    '    if (i >= uPointCount) break;',
    '    vec3 d = uPointPos[i].xyz - wpos;',
    '    float dist = length(d);',
    '    float range = uPointPos[i].w;',
    '    if (dist > range) continue;',
    '    vec3 Lp = d / max(dist, 1e-4);',
    '    float att = clamp(1.0 - dist / range, 0.0, 1.0); att *= att;',
    '    float ndlp = clamp((dot(N, Lp) + 0.25) / 1.25, 0.0, 1.0);',
    '    vec3 Hp = normalize(Lp + V);',
    '    float sp = min(D_GGX(max(dot(N,Hp),0.0), a) * V_Smith(ndv, max(dot(N,Lp),0.0), a), 6.0) * 0.5 * (1.0 - rough) * (1.0 - rough);',
    '    col += (diffCol / PI + F_Schlick(f0, max(dot(V,Hp),0.0)) * sp) * uPointCol[i].rgb * uPointCol[i].w * att * ndlp * PI;',
    '  }',
    '  return col;',
    '}',
    '',
    // --- туман с рассеиванием солнца ------------------------------------
    'vec3 applyFog(vec3 col, vec3 wpos){',
    '  vec3 v = wpos - uCamPos;',
    '  float dist = length(v);',
    '  vec3 dir = v / max(dist, 1e-4);',
    '  float hFall = max(uFogHeight, 1e-4);',
    '  float camH = exp(-max(uCamPos.y, 0.0) * hFall);',
    '  float f;',
    '  if (abs(dir.y) < 1e-3) f = uFogDens * dist * camH;',
    '  else f = uFogDens * camH * (1.0 - exp(-dir.y * hFall * dist)) / (dir.y * hFall);',
    '  f = 1.0 - exp(-max(f, 0.0));',
    '  float sun = max(dot(dir, uSunDir), 0.0);',
    '  vec3 fc = mix(uFogCol, uFogSunCol, pow(sun, 5.0) * 0.85);',
    '  return mix(col, fc, clamp(f, 0.0, 1.0));',
    '}',
    ''
  ].join('\n');

  global.GFX = { makeCommon: makeCommon, LIB: LIB, MAX_POINTS: MAX_POINTS };
})(window);
