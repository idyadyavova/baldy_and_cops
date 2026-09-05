/* =====================================================================
   post.js — постобработка: bloom, объёмные лучи света, SSAO,
   ACES-тонмаппинг, цветокоррекция, виньетка, зерно, FXAA.
   Всё на собственных full-screen проходах, без внешних библиотек.
   ===================================================================== */
(function (global) {
  'use strict';

  var QUAD_VERT = [
    'precision highp float;',
    'out vec2 vUv;',
    'void main(){ vUv = uv; gl_Position = vec4(position.xy, 0.0, 1.0); }'
  ].join('\n');

  function Pass(THREE, frag, uniforms) {
    this.material = new THREE.ShaderMaterial({
      glslVersion: THREE.GLSL3, uniforms: uniforms || {},
      vertexShader: QUAD_VERT, fragmentShader: frag,
      depthTest: false, depthWrite: false
    });
    this.mesh = new THREE.Mesh(new THREE.PlaneGeometry(2, 2), this.material);
    this.mesh.frustumCulled = false;
    this.scene = new THREE.Scene();
    this.scene.add(this.mesh);
    this.camera = new THREE.OrthographicCamera(-1, 1, 1, -1, 0, 1);
  }
  Pass.prototype.render = function (renderer, target) {
    renderer.setRenderTarget(target || null);
    renderer.render(this.scene, this.camera);
  };

  var ACES = [
    'vec3 RRTAndODTFit(vec3 v){ vec3 a = v * (v + 0.0245786) - 0.000090537; vec3 b = v * (0.983729 * v + 0.4329510) + 0.238081; return a / b; }',
    'vec3 ACESFitted(vec3 color){',
    '  const mat3 ACESInput = mat3(0.59719,0.07600,0.02840, 0.35458,0.90834,0.13383, 0.04823,0.01566,0.83777);',
    '  const mat3 ACESOutput = mat3(1.60475,-0.10208,-0.00327, -0.53108,1.10813,-0.07276, -0.07367,-0.00605,1.07602);',
    '  color = ACESInput * color;',
    '  color = RRTAndODTFit(color);',
    '  return clamp(ACESOutput * color, 0.0, 1.0);',
    '}'
  ].join('\n');

  function create(THREE, renderer, opts) {
    var pixelRatio = 1;
    var W = 2, H = 2;
    var half = THREE.HalfFloatType;

    function makeRT(w, h, o) {
      var rt = new THREE.WebGLRenderTarget(Math.max(2, Math.floor(w)), Math.max(2, Math.floor(h)), Object.assign({
        minFilter: THREE.LinearFilter, magFilter: THREE.LinearFilter,
        type: half, format: THREE.RGBAFormat, depthBuffer: false, stencilBuffer: false
      }, o || {}));
      return rt;
    }

    var sceneRT = makeRT(W, H, { depthBuffer: true });
    sceneRT.depthTexture = new THREE.DepthTexture(2, 2);
    sceneRT.depthTexture.type = THREE.UnsignedIntType;

    var aoRT = makeRT(W, H), aoBlurRT = makeRT(W, H), sceneAO = makeRT(W, H);
    var brightRT = makeRT(W, H);
    var blurA = [], blurB = [];
    var LEVELS = 4;
    for (var i = 0; i < LEVELS; i++) { blurA.push(makeRT(W, H)); blurB.push(makeRT(W, H)); }
    var rayRT = makeRT(W, H), rayRT2 = makeRT(W, H);
    var compRT = makeRT(W, H, { type: THREE.UnsignedByteType });

    // ---------- SSAO ----------
    var ssaoPass = new Pass(THREE, [
      'precision highp float;',
      'in vec2 vUv; out vec4 outColor;',
      'uniform sampler2D uDepth;',
      'uniform mat4 uProjInv, uProj;',
      'uniform vec2 uRes;',
      'uniform float uRadius, uStrength, uNear, uFar;',
      'float hash12(vec2 p){ vec3 p3=fract(vec3(p.xyx)*0.1031); p3+=dot(p3,p3.yzx+33.33); return fract((p3.x+p3.y)*p3.z); }',
      'vec3 viewPos(vec2 uv){',
      '  float d = texture(uDepth, uv).r;',
      '  vec4 c = uProjInv * vec4(uv * 2.0 - 1.0, d * 2.0 - 1.0, 1.0);',
      '  return c.xyz / c.w;',
      '}',
      'void main(){',
      '  float d = texture(uDepth, vUv).r;',
      '  if (d >= 0.9999) { outColor = vec4(1.0); return; }',
      '  vec3 P = viewPos(vUv);',
      '  vec3 n = normalize(cross(dFdx(P), dFdy(P)));',
      '  float ao = 0.0;',
      '  float ang = hash12(gl_FragCoord.xy) * 6.2831;',
      '  float rad = uRadius / max(-P.z, 1.0);',
      '  for (int i = 0; i < 12; i++) {',
      '    float f = (float(i) + 0.5) / 12.0;',
      '    float a = ang + f * 6.2831 * 3.0;',
      '    vec2 off = vec2(cos(a), sin(a)) * rad * sqrt(f);',
      '    vec3 S = viewPos(vUv + off);',
      '    vec3 dv = S - P;',
      '    float l = length(dv);',
      '    float occ = max(dot(n, dv / max(l, 1e-4)) - 0.06, 0.0);',
      '    ao += occ * (1.0 / (1.0 + l * l * 0.6));',
      '  }',
      '  ao = clamp(1.0 - ao / 12.0 * uStrength * 3.5, 0.0, 1.0);',
      '  outColor = vec4(ao, ao, ao, 1.0);',
      '}'
    ].join('\n'), {
      uDepth: { value: sceneRT.depthTexture }, uProjInv: { value: new THREE.Matrix4() },
      uProj: { value: new THREE.Matrix4() }, uRes: { value: new THREE.Vector2() },
      uRadius: { value: 0.55 }, uStrength: { value: 1.0 }, uNear: { value: 0.1 }, uFar: { value: 500 }
    });

    var blurAOPass = new Pass(THREE, [
      'precision highp float; in vec2 vUv; out vec4 outColor;',
      'uniform sampler2D uTex; uniform vec2 uDir;',
      'void main(){ float s = 0.0;',
      '  for (int i = -3; i <= 3; i++) s += texture(uTex, vUv + uDir * float(i)).r;',
      '  outColor = vec4(vec3(s / 7.0), 1.0); }'
    ].join('\n'), { uTex: { value: null }, uDir: { value: new THREE.Vector2() } });

    // ---------- применение AO к сцене ----------
    var applyAOPass = new Pass(THREE, [
      'precision highp float; in vec2 vUv; out vec4 outColor;',
      'uniform sampler2D uScene, uAO; uniform float uAmount;',
      'void main(){',
      '  vec3 c = texture(uScene, vUv).rgb;',
      '  float ao = texture(uAO, vUv).r;',
      '  ao = mix(1.0, ao, uAmount);',
      '  outColor = vec4(c * ao, 1.0);',
      '}'
    ].join('\n'), { uScene: { value: null }, uAO: { value: null }, uAmount: { value: 0.8 } });

    // ---------- bright pass ----------
    var brightPass = new Pass(THREE, [
      'precision highp float; in vec2 vUv; out vec4 outColor;',
      'uniform sampler2D uTex; uniform float uThreshold, uKnee;',
      'void main(){',
      '  vec3 c = texture(uTex, vUv).rgb;',
      '  float l = max(c.r, max(c.g, c.b));',
      '  float soft = clamp(l - uThreshold + uKnee, 0.0, 2.0 * uKnee);',
      '  soft = soft * soft / (4.0 * uKnee + 1e-4);',
      '  float w = max(soft, l - uThreshold) / max(l, 1e-4);',
      '  outColor = vec4(c * w, 1.0);',
      '}'
    ].join('\n'), { uTex: { value: null }, uThreshold: { value: 1.0 }, uKnee: { value: 0.6 } });

    var blurPass = new Pass(THREE, [
      'precision highp float; in vec2 vUv; out vec4 outColor;',
      'uniform sampler2D uTex; uniform vec2 uDir;',
      'void main(){',
      '  vec3 s = texture(uTex, vUv).rgb * 0.227027;',
      '  s += (texture(uTex, vUv + uDir * 1.3846).rgb + texture(uTex, vUv - uDir * 1.3846).rgb) * 0.316216;',
      '  s += (texture(uTex, vUv + uDir * 3.2308).rgb + texture(uTex, vUv - uDir * 3.2308).rgb) * 0.070270;',
      '  outColor = vec4(s, 1.0);',
      '}'
    ].join('\n'), { uTex: { value: null }, uDir: { value: new THREE.Vector2() } });

    var downPass = new Pass(THREE, [
      'precision highp float; in vec2 vUv; out vec4 outColor;',
      'uniform sampler2D uTex; uniform vec2 uTexel;',
      'void main(){',
      '  vec3 s = texture(uTex, vUv).rgb * 0.5;',
      '  s += texture(uTex, vUv + uTexel * vec2(-1.0, -1.0)).rgb * 0.125;',
      '  s += texture(uTex, vUv + uTexel * vec2( 1.0, -1.0)).rgb * 0.125;',
      '  s += texture(uTex, vUv + uTexel * vec2(-1.0,  1.0)).rgb * 0.125;',
      '  s += texture(uTex, vUv + uTexel * vec2( 1.0,  1.0)).rgb * 0.125;',
      '  outColor = vec4(s, 1.0);',
      '}'
    ].join('\n'), { uTex: { value: null }, uTexel: { value: new THREE.Vector2() } });

    var upPass = new Pass(THREE, [
      'precision highp float; in vec2 vUv; out vec4 outColor;',
      'uniform sampler2D uTex, uAdd; uniform float uK;',
      'void main(){ outColor = vec4(texture(uTex, vUv).rgb + texture(uAdd, vUv).rgb * uK, 1.0); }'
    ].join('\n'), { uTex: { value: null }, uAdd: { value: null }, uK: { value: 1.0 } });

    // ---------- объёмные лучи ----------
    var rayMaskPass = new Pass(THREE, [
      'precision highp float; in vec2 vUv; out vec4 outColor;',
      'uniform sampler2D uScene, uDepth; uniform vec2 uSunUv; uniform vec3 uSunCol;',
      'void main(){',
      '  float d = texture(uDepth, vUv).r;',
      '  vec3 c = texture(uScene, vUv).rgb;',
      '  float sky = d >= 0.99995 ? 1.0 : 0.0;',
      '  float l = max(c.r, max(c.g, c.b));',
      '  float m = sky * smoothstep(0.75, 2.2, l);',
      '  float dist = length((vUv - uSunUv) * vec2(1.0, 0.62));',
      '  m *= exp(-dist * 2.2);',
      '  outColor = vec4(c * m, 1.0);',
      '}'
    ].join('\n'), { uScene: { value: null }, uDepth: { value: sceneRT.depthTexture }, uSunUv: { value: new THREE.Vector2(0.5, 0.5) }, uSunCol: { value: new THREE.Color() } });

    var rayBlurPass = new Pass(THREE, [
      'precision highp float; in vec2 vUv; out vec4 outColor;',
      'uniform sampler2D uTex; uniform vec2 uSunUv; uniform float uDensity, uDecay;',
      'float hash12(vec2 p){ vec3 p3=fract(vec3(p.xyx)*0.1031); p3+=dot(p3,p3.yzx+33.33); return fract((p3.x+p3.y)*p3.z); }',
      'void main(){',
      '  vec2 dir = (vUv - uSunUv) * uDensity / 24.0;',
      '  vec2 uv = vUv - dir * hash12(gl_FragCoord.xy);',
      '  vec3 sum = vec3(0.0); float w = 1.0, tw = 0.0;',
      '  for (int i = 0; i < 24; i++) {',
      '    sum += texture(uTex, uv).rgb * w; tw += w;',
      '    uv -= dir; w *= uDecay;',
      '  }',
      '  outColor = vec4(sum / max(tw, 1e-4), 1.0);',
      '}'
    ].join('\n'), { uTex: { value: null }, uSunUv: { value: new THREE.Vector2(0.5, 0.5) }, uDensity: { value: 1.0 }, uDecay: { value: 0.955 } });

    // ---------- финальная сборка ----------
    var compPass = new Pass(THREE, [
      'precision highp float; in vec2 vUv; out vec4 outColor;',
      'uniform sampler2D uScene, uBloom, uRays, uDepth;',
      'uniform float uExposure, uBloomAmt, uRaysAmt, uTime, uVignette, uGrain, uChroma;',
      'uniform float uSaturation, uContrast, uAberration;',
      'uniform vec3 uLift, uGain, uRaysCol;',
      'uniform vec2 uSunUv; uniform float uSunUp;',
      ACES,
      'float hash12(vec2 p){ vec3 p3=fract(vec3(p.xyx)*0.1031); p3+=dot(p3,p3.yzx+33.33); return fract((p3.x+p3.y)*p3.z); }',
      'void main(){',
      '  vec2 uv = vUv;',
      '  vec2 cc = uv - 0.5;',
      '  float r2 = dot(cc, cc);',
      // хроматическая аберрация к краям кадра
      '  vec3 col;',
      '  if (uAberration > 0.0001) {',
      '    vec2 off = cc * r2 * uAberration;',
      '    col.r = texture(uScene, uv + off).r;',
      '    col.g = texture(uScene, uv).g;',
      '    col.b = texture(uScene, uv - off).b;',
      '  } else col = texture(uScene, uv).rgb;',
      '  col += texture(uBloom, uv).rgb * uBloomAmt;',
      '  col += texture(uRays, uv).rgb * uRaysCol * uRaysAmt;',
      '  col *= uExposure;',
      '  col = ACESFitted(col);',
      // цветокоррекция: lift / gain, контраст, насыщенность
      '  col = col * uGain + uLift * (1.0 - col);',
      '  col = clamp((col - 0.5) * uContrast + 0.5, 0.0, 1.0);',
      '  float lum = dot(col, vec3(0.2126, 0.7152, 0.0722));',
      '  col = clamp(mix(vec3(lum), col, uSaturation), 0.0, 1.0);',
      // сплит-тонирование: холодные тени, тёплые света
      '  col = mix(col * vec3(0.96, 0.99, 1.06), col * vec3(1.05, 1.0, 0.94), smoothstep(0.25, 0.85, lum));',
      // виньетка
      '  float vig = 1.0 - uVignette * smoothstep(0.16, 0.86, r2 * 1.9);',
      '  col *= vig;',
      // плёночное зерно
      '  float g = hash12(gl_FragCoord.xy + fract(uTime) * 137.0) - 0.5;',
      '  col += g * uGrain * (1.0 - lum * 0.65);',
      '  outColor = vec4(pow(clamp(col, 0.0, 1.0), vec3(1.0 / 2.2)), 1.0);',
      '}'
    ].join('\n'), {
      uScene: { value: null }, uBloom: { value: null }, uRays: { value: null }, uDepth: { value: sceneRT.depthTexture },
      uExposure: { value: 1.0 }, uBloomAmt: { value: 0.55 }, uRaysAmt: { value: 0.5 },
      uTime: { value: 0 }, uVignette: { value: 0.38 }, uGrain: { value: 0.011 }, uChroma: { value: 0 },
      uSaturation: { value: 1.18 }, uContrast: { value: 1.13 }, uAberration: { value: 0.0075 },
      uLift: { value: new THREE.Vector3(0.008, 0.010, 0.020) },
      uGain: { value: new THREE.Vector3(1.02, 1.0, 0.98) },
      uRaysCol: { value: new THREE.Vector3(1, 0.92, 0.75) },
      uSunUv: { value: new THREE.Vector2(0.5, 0.5) }, uSunUp: { value: 1 }
    });

    var fxaaPass = new Pass(THREE, [
      'precision highp float; in vec2 vUv; out vec4 outColor;',
      'uniform sampler2D uTex; uniform vec2 uTexel;',
      'float lum(vec3 c){ return dot(c, vec3(0.299, 0.587, 0.114)); }',
      'void main(){',
      '  vec3 rgbNW = texture(uTex, vUv + vec2(-1.0, -1.0) * uTexel).rgb;',
      '  vec3 rgbNE = texture(uTex, vUv + vec2( 1.0, -1.0) * uTexel).rgb;',
      '  vec3 rgbSW = texture(uTex, vUv + vec2(-1.0,  1.0) * uTexel).rgb;',
      '  vec3 rgbSE = texture(uTex, vUv + vec2( 1.0,  1.0) * uTexel).rgb;',
      '  vec3 rgbM  = texture(uTex, vUv).rgb;',
      '  float lNW = lum(rgbNW), lNE = lum(rgbNE), lSW = lum(rgbSW), lSE = lum(rgbSE), lM = lum(rgbM);',
      '  float lMin = min(lM, min(min(lNW, lNE), min(lSW, lSE)));',
      '  float lMax = max(lM, max(max(lNW, lNE), max(lSW, lSE)));',
      '  vec2 dir = vec2(-((lNW + lNE) - (lSW + lSE)), ((lNW + lSW) - (lNE + lSE)));',
      '  float dirReduce = max((lNW + lNE + lSW + lSE) * 0.03125, 0.0078125);',
      '  float rcpDirMin = 1.0 / (min(abs(dir.x), abs(dir.y)) + dirReduce);',
      '  dir = clamp(dir * rcpDirMin, -8.0, 8.0) * uTexel;',
      '  vec3 rgbA = 0.5 * (texture(uTex, vUv + dir * (1.0/3.0 - 0.5)).rgb + texture(uTex, vUv + dir * (2.0/3.0 - 0.5)).rgb);',
      '  vec3 rgbB = rgbA * 0.5 + 0.25 * (texture(uTex, vUv - dir * 0.5).rgb + texture(uTex, vUv + dir * 0.5).rgb);',
      '  float lB = lum(rgbB);',
      '  outColor = vec4((lB < lMin || lB > lMax) ? rgbA : rgbB, 1.0);',
      '}'
    ].join('\n'), { uTex: { value: null }, uTexel: { value: new THREE.Vector2() } });

    var api = {
      sceneRT: sceneRT,
      settings: {
        bloom: true, rays: true, ssao: true, fxaa: true,
        bloomAmt: 0.55, raysAmt: 0.55, ssaoAmt: 0.75, exposure: 1.0
      },
      comp: compPass, ssao: ssaoPass, rays: rayBlurPass, bright: brightPass,
      setSize: function (w, h, ratio) {
        W = Math.max(2, Math.floor(w * ratio)); H = Math.max(2, Math.floor(h * ratio));
        pixelRatio = ratio;
        sceneRT.setSize(W, H);
        sceneRT.depthTexture.image.width = W; sceneRT.depthTexture.image.height = H;
        sceneRT.depthTexture.needsUpdate = true;
        compRT.setSize(W, H); sceneAO.setSize(W, H);
        aoRT.setSize(W >> 1, H >> 1); aoBlurRT.setSize(W >> 1, H >> 1);
        brightRT.setSize(W >> 1, H >> 1);
        for (var i = 0; i < LEVELS; i++) {
          var d = 2 << i;
          blurA[i].setSize(Math.max(2, W / d), Math.max(2, H / d));
          blurB[i].setSize(Math.max(2, W / d), Math.max(2, H / d));
        }
        rayRT.setSize(W >> 1, H >> 1); rayRT2.setSize(W >> 1, H >> 1);
      },
      render: function (camera, sunScreen, sunUp) {
        var s = api.settings;
        var src = sceneRT.texture;

        // --- SSAO ---
        if (s.ssao) {
          ssaoPass.material.uniforms.uProjInv.value.copy(camera.projectionMatrixInverse);
          ssaoPass.material.uniforms.uRes.value.set(W >> 1, H >> 1);
          ssaoPass.render(renderer, aoRT);
          blurAOPass.material.uniforms.uTex.value = aoRT.texture;
          blurAOPass.material.uniforms.uDir.value.set(1.4 / (W >> 1), 0);
          blurAOPass.render(renderer, aoBlurRT);
          blurAOPass.material.uniforms.uTex.value = aoBlurRT.texture;
          blurAOPass.material.uniforms.uDir.value.set(0, 1.4 / (H >> 1));
          blurAOPass.render(renderer, aoRT);
          applyAOPass.material.uniforms.uScene.value = sceneRT.texture;
          applyAOPass.material.uniforms.uAO.value = aoRT.texture;
          applyAOPass.material.uniforms.uAmount.value = s.ssaoAmt;
          applyAOPass.render(renderer, sceneAO);
          src = sceneAO.texture;
        }

        // --- bloom ---
        var bloomTex = null;
        if (s.bloom) {
          brightPass.material.uniforms.uTex.value = src;
          brightPass.render(renderer, brightRT);
          var cur = brightRT;
          for (var i = 0; i < LEVELS; i++) {
            downPass.material.uniforms.uTex.value = cur.texture;
            downPass.material.uniforms.uTexel.value.set(1 / cur.width, 1 / cur.height);
            downPass.render(renderer, blurA[i]);
            blurPass.material.uniforms.uTex.value = blurA[i].texture;
            blurPass.material.uniforms.uDir.value.set(1 / blurA[i].width, 0);
            blurPass.render(renderer, blurB[i]);
            blurPass.material.uniforms.uTex.value = blurB[i].texture;
            blurPass.material.uniforms.uDir.value.set(0, 1 / blurA[i].height);
            blurPass.render(renderer, blurA[i]);
            cur = blurA[i];
          }
          for (var j = LEVELS - 2; j >= 0; j--) {
            upPass.material.uniforms.uTex.value = blurA[j].texture;
            upPass.material.uniforms.uAdd.value = cur.texture;
            upPass.material.uniforms.uK.value = 0.85;
            upPass.render(renderer, blurB[j]);
            cur = blurB[j];
          }
          bloomTex = cur.texture;
        }

        // --- лучи света ---
        var raysTex = null;
        if (s.rays && sunUp > 0.01) {
          rayMaskPass.material.uniforms.uScene.value = src;
          rayMaskPass.material.uniforms.uSunUv.value.copy(sunScreen);
          rayMaskPass.render(renderer, rayRT);
          rayBlurPass.material.uniforms.uTex.value = rayRT.texture;
          rayBlurPass.material.uniforms.uSunUv.value.copy(sunScreen);
          rayBlurPass.render(renderer, rayRT2);
          raysTex = rayRT2.texture;
        }

        // --- сборка ---
        var cu = compPass.material.uniforms;
        cu.uScene.value = src;
        cu.uBloom.value = bloomTex || blurA[LEVELS - 1].texture;
        cu.uRays.value = raysTex || rayRT2.texture;
        cu.uBloomAmt.value = s.bloom ? s.bloomAmt : 0.0;
        cu.uRaysAmt.value = (s.rays && raysTex) ? s.raysAmt * sunUp : 0.0;
        cu.uExposure.value = s.exposure;
        cu.uSunUv.value.copy(sunScreen);
        compPass.render(renderer, s.fxaa ? compRT : null);

        if (s.fxaa) {
          fxaaPass.material.uniforms.uTex.value = compRT.texture;
          fxaaPass.material.uniforms.uTexel.value.set(1 / W, 1 / H);
          fxaaPass.render(renderer, null);
        }
      }
    };
    return api;
  }

  global.POST = { create: create, Pass: Pass, ACES: ACES, QUAD_VERT: QUAD_VERT };
})(window);
