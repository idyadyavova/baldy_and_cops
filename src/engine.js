/* =====================================================================
   engine.js — рендер-ядро: сцена, теневой проход, небо, постобработка,
   отражения воды, качество графики.
   ===================================================================== */
(function (global) {
  'use strict';

  var QUALITY = {
    low: { shadow: 1024, render: 0.72, pom: 0, detail: 0.6, ssao: false, rays: false, bloom: true, fxaa: false, grass: 0.25, texSize: 128, water: 0 },
    medium: { shadow: 1536, render: 0.85, pom: 0.6, detail: 1.0, ssao: false, rays: true, bloom: true, fxaa: true, grass: 0.5, texSize: 256, water: 1 },
    high: { shadow: 2048, render: 1.0, pom: 1.0, detail: 1.0, ssao: true, rays: true, bloom: true, fxaa: true, grass: 0.85, texSize: 256, water: 1 },
    ultra: { shadow: 3072, render: 1.0, pom: 1.4, detail: 1.2, ssao: true, rays: true, bloom: true, fxaa: true, grass: 1.2, texSize: 256, water: 1 }
  };

  function create(THREE, canvas, quality) {
    var renderer = new THREE.WebGLRenderer({
      canvas: canvas, antialias: false, alpha: false,
      powerPreference: 'high-performance', stencil: false, depth: true
    });
    renderer.autoClear = true;
    renderer.info.autoReset = false;
    renderer.setClearColor(0x000000, 1);
    renderer.outputEncoding = THREE.LinearEncoding;
    renderer.shadowMap.enabled = false;   // собственная реализация теней

    var common = global.GFX.makeCommon(THREE);
    var scene = new THREE.Scene();
    var camera = new THREE.PerspectiveCamera(64, 1, 0.08, 600);

    var sky = global.SKY.create(THREE, common);
    scene.add(sky.mesh);

    var post = global.POST.create(THREE, renderer, {});

    // --- теневая карта -------------------------------------------
    var shadowSize = QUALITY[quality].shadow;
    var shadowRT = new THREE.WebGLRenderTarget(shadowSize, shadowSize, {
      minFilter: THREE.NearestFilter, magFilter: THREE.NearestFilter,
      format: THREE.RGBAFormat, type: THREE.UnsignedByteType,
      depthBuffer: true, stencilBuffer: false
    });
    shadowRT.depthTexture = new THREE.DepthTexture(shadowSize, shadowSize);
    shadowRT.depthTexture.type = THREE.UnsignedIntType;
    shadowRT.depthTexture.minFilter = THREE.NearestFilter;
    shadowRT.depthTexture.magFilter = THREE.NearestFilter;
    common.uShadowMap.value = shadowRT.depthTexture;
    common.uShadowTexel.value = 1 / shadowSize;

    var sunCam = new THREE.OrthographicCamera(-30, 30, 30, -30, 0.5, 260);
    var shadowBias = new THREE.Matrix4().set(0.5, 0, 0, 0.5, 0, 0.5, 0, 0.5, 0, 0, 0.5, 0.5, 0, 0, 0, 1);

    var eng = {
      THREE: THREE, renderer: renderer, scene: scene, camera: camera,
      common: common, sky: sky, post: post,
      quality: quality, Q: QUALITY[quality],
      sunCam: sunCam, shadowRT: shadowRT,
      shadowRadius: 34,
      timeOfDay: 0.30,          // высота солнца: 1 = зенит, <0 — ночь
      sunAzimuth: 0.7,
      renderScale: QUALITY[quality].render,
      width: 2, height: 2,
      water: null,
      exposureBias: 1.0,
      shadowTarget: new THREE.Vector3()
    };

    eng.setQuality = function (q) {
      eng.quality = q; eng.Q = QUALITY[q];
      eng.renderScale = QUALITY[q].render;
      post.settings.ssao = QUALITY[q].ssao;
      post.settings.rays = QUALITY[q].rays;
      post.settings.bloom = QUALITY[q].bloom;
      post.settings.fxaa = QUALITY[q].fxaa;
      eng.resize(eng.width, eng.height, eng.dpr || 1);
    };

    eng.resize = function (w, h, dpr) {
      eng.width = w; eng.height = h; eng.dpr = dpr;
      var scale = eng.renderScale * dpr;
      renderer.setPixelRatio(1);
      renderer.setSize(w, h, false);
      camera.aspect = w / h;
      camera.updateProjectionMatrix();
      post.setSize(w, h, scale);
      eng.rtW = Math.floor(w * scale); eng.rtH = Math.floor(h * scale);
    };

    // --- время суток --------------------------------------------
    var _sunDir = new THREE.Vector3(), _moonDir = new THREE.Vector3();
    eng.setTimeOfDay = function (elev, azim, cloudCover) {
      eng.timeOfDay = elev;
      eng.sunAzimuth = azim === undefined ? eng.sunAzimuth : azim;
      var a = eng.sunAzimuth;
      var el = elev * Math.PI * 0.5;
      _sunDir.set(Math.cos(a) * Math.cos(el), Math.sin(el), Math.sin(a) * Math.cos(el)).normalize();
      _moonDir.set(-_sunDir.x, -_sunDir.y * 0.85 + 0.25, -_sunDir.z).normalize();
      var night = _sunDir.y < 0.02;
      var key = night ? _moonDir : _sunDir;
      common.uSunDir.value.copy(key);
      var p = global.SKY.samplePalette(_sunDir.y);
      sky.uniforms.uTop.value.setRGB(p.top[0], p.top[1], p.top[2]);
      sky.uniforms.uHor.value.setRGB(p.hor[0], p.hor[1], p.hor[2]);
      sky.uniforms.uSunCol.value.setRGB(p.sun[0], p.sun[1], p.sun[2]);
      sky.uniforms.uCloudLit.value.setRGB(p.cloudLit[0], p.cloudLit[1], p.cloudLit[2]);
      sky.uniforms.uCloudDark.value.setRGB(p.cloudDark[0], p.cloudDark[1], p.cloudDark[2]);
      sky.uniforms.uStars.value = p.stars;
      sky.uniforms.uMoonDir.value.copy(_moonDir);
      sky.uniforms.uSunPos.value.copy(_sunDir);
      if (cloudCover !== undefined) sky.uniforms.uCloudCover.value = cloudCover;

      // свет сцены
      var sunI = night ? 0.42 : 1.0;
      common.uSunColor.value.setRGB(p.sun[0] * sunI, p.sun[1] * sunI, p.sun[2] * sunI);
      // для освещения небо приглушаем по насыщенности — иначе тени уходят в синеву
      function amb(c, k, m) {
        var l = 0.30 * c[0] + 0.60 * c[1] + 0.10 * c[2];
        return [(c[0] + (l - c[0]) * k) * m, (c[1] + (l - c[1]) * k) * m, (c[2] + (l - c[2]) * k) * m];
      }
      var at = amb(p.top, 0.72, 0.92), ah = amb(p.hor, 0.55, 1.0);
      // немного солнечного цвета в тень — иначе тени синие, как на луне
      var sm = night ? 0.06 : 0.42;
      var sn = Math.max(0.001, (p.sun[0] + p.sun[1] + p.sun[2]) / 3);
      for (var ci = 0; ci < 3; ci++) {
        at[ci] = at[ci] * (1 - sm) + (p.sun[ci] / sn) * at[ci] * sm;
        ah[ci] = ah[ci] * (1 - sm) + (p.sun[ci] / sn) * ah[ci] * sm;
      }
      common.uSkyTop.value.setRGB(at[0], at[1], at[2]);
      common.uSkyHor.value.setRGB(ah[0], ah[1], ah[2]);
      common.uGroundCol.value.setRGB(p.ground[0], p.ground[1], p.ground[2]);
      common.uAmbient.value = p.amb;
      common.uFogCol.value.setRGB(p.fog[0], p.fog[1], p.fog[2]);
      common.uFogSunCol.value.setRGB(p.fogSun[0], p.fogSun[1], p.fogSun[2]);
      common.uShadowStrength.value = night ? 0.55 : 1.0;
      post.settings.exposure = p.exposure * eng.exposureBias;
      eng.palette = p;
      eng.isNight = night;
    };

    // --- проход теней -------------------------------------------
    var _sv = new THREE.Vector3(), _swap = [];
    eng.renderShadows = function (target) {
      var r = eng.shadowRadius;
      var dist = r * 1.6 + 24;
      sunCam.left = -r; sunCam.right = r; sunCam.top = r; sunCam.bottom = -r;
      sunCam.near = Math.max(0.5, dist - r * 1.9);
      sunCam.far = dist + r * 1.9;
      var range = sunCam.far - sunCam.near;
      common.uShadowBias.value = 0.055 / range;
      common.uShadowNormalOff.value = (r * 2 / shadowSize) * 1.35;
      var d = common.uSunDir.value;
      // привязка к сетке текселей — убирает дрожание теней
      var texel = (r * 2) / shadowSize;
      var tx = Math.round(target.x / texel) * texel;
      var tz = Math.round(target.z / texel) * texel;
      var ty = Math.round(target.y / texel) * texel;
      _sv.set(tx, ty, tz);
      sunCam.position.copy(_sv).addScaledVector(d, dist);
      sunCam.lookAt(_sv);
      sunCam.updateMatrixWorld();
      sunCam.updateProjectionMatrix();

      common.uShadowMat.value.copy(shadowBias)
        .multiply(sunCam.projectionMatrix)
        .multiply(sunCam.matrixWorldInverse);

      _swap.length = 0;
      scene.traverse(function (o) {
        var drawable = o.isMesh || o.isPoints || o.isLine || o.isSprite;
        if (!drawable) return;
        if (o.isMesh && o.userData.depthMat && o.visible) {
          _swap.push([o, o.material]);
          o.material = o.userData.depthMat;
        } else if (!o.userData.depthMat) {
          o.userData._hidden = o.visible;
          o.visible = false;
        }
      });
      renderer.setRenderTarget(shadowRT);
      renderer.clear(true, true, false);
      renderer.render(scene, sunCam);
      for (var i = 0; i < _swap.length; i++) _swap[i][0].material = _swap[i][1];
      scene.traverse(function (o) {
        if (o.userData._hidden !== undefined) {
          o.visible = o.userData._hidden; o.userData._hidden = undefined;
        }
      });
      renderer.setRenderTarget(null);
    };

    // --- позиция солнца на экране (для лучей) --------------------
    var _sp = new THREE.Vector3(), sunScreen = new THREE.Vector2(0.5, 0.5);
    function computeSunScreen() {
      var dir = eng.isNight ? common.uSunDir.value : sky.uniforms.uSunPos.value;
      _sp.copy(camera.position).addScaledVector(dir, 400);
      _sp.project(camera);
      sunScreen.set(_sp.x * 0.5 + 0.5, _sp.y * 0.5 + 0.5);
      var behind = _sp.z > 1;
      var up = sky.uniforms.uSunPos.value.y;
      var vis = behind ? 0 : Math.max(0, Math.min(1, (up + 0.03) * 6));
      // ослабляем, когда солнце далеко от центра кадра
      var dx = Math.abs(sunScreen.x - 0.5), dy = Math.abs(sunScreen.y - 0.5);
      vis *= Math.max(0, 1 - Math.max(dx, dy * 1.2) * 0.85);
      return vis;
    }

    // --- кадр ----------------------------------------------------
    eng.render = function (dt) {
      renderer.info.reset();
      common.uCamPos.value.copy(camera.position);
      sky.mesh.position.copy(camera.position);
      sky.mesh.scale.setScalar(camera.far * 0.45);   // купол должен быть внутри far-плоскости
      var sunVis = computeSunScreen();
      post.comp.material.uniforms.uTime.value = common.uTime.value;
      if (eng.water) eng.water.updateReflection(eng, camera);
      eng.renderShadows(eng.shadowTarget);
      renderer.setRenderTarget(post.sceneRT);
      renderer.clear(true, true, false);
      renderer.render(scene, camera);
      renderer.setRenderTarget(null);
      post.render(camera, sunScreen, sunVis);
      eng.stats = { calls: renderer.info.render.calls, tris: renderer.info.render.triangles };
    };

    eng.sunScreen = sunScreen;
    return eng;
  }

  global.ENGINE = { create: create, QUALITY: QUALITY };
})(window);
