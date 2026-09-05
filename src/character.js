/* =====================================================================
   character.js — персонажи из гладкой органической геометрии.
   Тело строится лофтом эллиптических сечений (грудь, руки, ноги),
   голова — деформированная сфера с лицом. Никаких кубов и палок.
   Анимация процедурная: шаг, бег, дыхание, наклоны, поворот головы.
   ===================================================================== */
(function (global) {
  'use strict';

  // ---- лофт: набор сечений -> гладкая поверхность -----------------
  function loft(THREE, sections, radial, capBottom, capTop) {
    var pos = [], nrm = [], uv = [], idx = [];
    var n = sections.length;
    for (var i = 0; i < n; i++) {
      var s = sections[i];
      for (var j = 0; j <= radial; j++) {
        var a = (j / radial) * Math.PI * 2;
        var ca = Math.cos(a), sa = Math.sin(a);
        // «квадратность» сечения: 0 — эллипс, 1 — почти прямоугольник
        var sq = s.sq || 0;
        var kx = Math.sign(ca) * Math.pow(Math.abs(ca), 1 - sq * 0.55);
        var kz = Math.sign(sa) * Math.pow(Math.abs(sa), 1 - sq * 0.55);
        pos.push((s.ox || 0) + kx * s.rx, s.y, (s.oz || 0) + kz * s.rz);
        nrm.push(kx * s.rz, 0, kz * s.rx);
        uv.push(j / radial, i / (n - 1));
      }
    }
    for (var i2 = 0; i2 < n - 1; i2++) {
      for (var j2 = 0; j2 < radial; j2++) {
        var a0 = i2 * (radial + 1) + j2, b0 = a0 + 1;
        var a1 = (i2 + 1) * (radial + 1) + j2, b1 = a1 + 1;
        idx.push(a0, a1, b1, a0, b1, b0);
      }
    }
    // крышки
    function cap(sIdx, flip) {
      var s = sections[sIdx];
      var c = pos.length / 3;
      pos.push(s.ox || 0, s.y + (flip ? 0.012 : -0.012), s.oz || 0);
      nrm.push(0, flip ? 1 : -1, 0); uv.push(0.5, flip ? 1 : 0);
      var base = sIdx * (radial + 1);
      for (var j3 = 0; j3 < radial; j3++) {
        if (flip) idx.push(c, base + j3, base + j3 + 1);
        else idx.push(c, base + j3 + 1, base + j3);
      }
    }
    if (capBottom) cap(0, false);
    if (capTop) cap(n - 1, true);
    var g = new THREE.BufferGeometry();
    g.setAttribute('position', new THREE.Float32BufferAttribute(pos, 3));
    g.setAttribute('normal', new THREE.Float32BufferAttribute(nrm, 3));
    g.setAttribute('uv', new THREE.Float32BufferAttribute(uv, 2));
    g.setIndex(idx);
    g.computeVertexNormals();
    return g;
  }

  // конечность: плавно сужающийся «капсульный» объём
  function limb(THREE, len, r0, r1, radial, bulge) {
    var secs = [];
    var steps = 7;
    for (var i = 0; i <= steps; i++) {
      var t = i / steps;
      var r = r0 + (r1 - r0) * t;
      // мышечное утолщение сверху
      r *= 1 + Math.sin(t * Math.PI) * (bulge === undefined ? 0.16 : bulge);
      // скругление концов
      var e = Math.min(t, 1 - t) * 2;
      r *= 0.55 + 0.45 * Math.pow(Math.min(1, e * 2.2), 0.5);
      secs.push({ y: -len * t, rx: r, rz: r * 0.94 });
    }
    return loft(THREE, secs, radial, true, true);
  }

  function deformedSphere(THREE, r, seg, fn) {
    var g = new THREE.SphereGeometry(r, seg, Math.max(6, seg * 0.7));
    var p = g.attributes.position;
    for (var i = 0; i < p.count; i++) {
      var x = p.getX(i), y = p.getY(i), z = p.getZ(i);
      var v = fn(x, y, z);
      p.setXYZ(i, v[0], v[1], v[2]);
    }
    g.computeVertexNormals();
    return g;
  }

  // ---- палитры ---------------------------------------------------
  var SKINS = [
    { name: 'Лысый', skin: 0xe8a68a, suit: 0xc0392b, suitDark: 0x7d2018, pants: 0x3f5478, shoe: 0xf0f0f0, stripe: 0xf5f5f5, brow: 0x3a2a22 },
    { name: 'Синий', skin: 0xd99b7d, suit: 0x2b6cb0, suitDark: 0x1c4a7d, pants: 0x35476b, shoe: 0x2c2c2c, stripe: 0xe8e8e8, brow: 0x2a2018 },
    { name: 'Зелёный', skin: 0xf0b48f, suit: 0x2f8f5b, suitDark: 0x1d6440, pants: 0x4a5240, shoe: 0xdcdcdc, stripe: 0xf2f2f2, brow: 0x4a3020 },
    { name: 'Чёрный', skin: 0xc98a68, suit: 0x2a2a30, suitDark: 0x18181c, pants: 0x2e2e38, shoe: 0xb03030, stripe: 0xd0d0d0, brow: 0x18120c }
  ];
  var COP = { name: 'Мент', skin: 0xd9a184, suit: 0x3f5f96, suitDark: 0x27395c, pants: 0x3a4d72, shoe: 0x232323, stripe: 0xd8d8d8, brow: 0x241a12 };

  function mats(THREE, common, pal, isCop) {
    var MAT = global.MAT;
    var m = {
      skin: MAT.makeObjectMaterial(THREE, common, {
        color: pal.skin, rough: 0.62, noiseAmt: 0.16, noiseScale: 44, subsurface: 0.9, rim: 0x241008
      }),
      suit: MAT.makeObjectMaterial(THREE, common, {
        color: pal.suit, rough: 0.86, noiseAmt: 0.26, noiseScale: 26, rim: 0x0a0a12
      }),
      suitDark: MAT.makeObjectMaterial(THREE, common, {
        color: pal.suitDark, rough: 0.88, noiseAmt: 0.22, noiseScale: 30
      }),
      pants: MAT.makeObjectMaterial(THREE, common, {
        color: pal.pants, rough: 0.90, noiseAmt: 0.30, noiseScale: 34
      }),
      shoe: MAT.makeObjectMaterial(THREE, common, {
        color: pal.shoe, rough: 0.55, noiseAmt: 0.14, noiseScale: 40
      }),
      stripe: MAT.makeObjectMaterial(THREE, common, { color: pal.stripe, rough: 0.7, noiseAmt: 0.1 }),
      dark: MAT.makeObjectMaterial(THREE, common, { color: pal.brow, rough: 0.75, noiseAmt: 0.2, noiseScale: 40 }),
      eye: MAT.makeObjectMaterial(THREE, common, { color: 0xf2f2f0, rough: 0.18 }),
      iris: MAT.makeObjectMaterial(THREE, common, { color: 0x2a1a10, rough: 0.12 }),
      metal: MAT.makeObjectMaterial(THREE, common, { color: 0xd8c060, rough: 0.25, metal: 0.9 }),
      mouth: MAT.makeObjectMaterial(THREE, common, { color: 0x5a1a1a, rough: 0.45 })
    };
    if (isCop) {
      m.cap = MAT.makeObjectMaterial(THREE, common, { color: 0x28344f, rough: 0.72, noiseAmt: 0.18, noiseScale: 30 });
      m.visor = MAT.makeObjectMaterial(THREE, common, { color: 0x101014, rough: 0.30 });
      m.vest = MAT.makeObjectMaterial(THREE, common, { color: 0x2c313d, rough: 0.7, noiseAmt: 0.2 });
    }
    return m;
  }

  // ---- сборка тела ------------------------------------------------
  function build(THREE, common, palIndex, isCop, depthMat) {
    var pal = isCop ? COP : SKINS[palIndex % SKINS.length];
    var M = mats(THREE, common, pal, isCop);
    var R = 14;   // радиальных сегментов

    function mesh(geo, mat, parent, x, y, z) {
      var m = new THREE.Mesh(geo, mat);
      m.position.set(x || 0, y || 0, z || 0);
      m.userData.depthMat = depthMat;
      if (parent) parent.add(m);
      return m;
    }
    function node(parent, x, y, z) {
      var o = new THREE.Object3D();
      o.position.set(x || 0, y || 0, z || 0);
      if (parent) parent.add(o);
      return o;
    }

    var root = new THREE.Object3D();
    var hips = node(root, 0, 0.92, 0);

    // --- таз и торс (лофт) ---
    var torsoSec = [
      { y: -0.10, rx: 0.155, rz: 0.115, sq: 0.25 },
      { y: 0.00, rx: 0.170, rz: 0.125, sq: 0.25 },
      { y: 0.10, rx: 0.155, rz: 0.112, sq: 0.2 },
      { y: 0.20, rx: 0.163, rz: 0.118, sq: 0.2 },
      { y: 0.32, rx: 0.196, rz: 0.135, sq: 0.25 },
      { y: 0.42, rx: 0.205, rz: 0.138, sq: 0.3 },
      { y: 0.50, rx: 0.178, rz: 0.122, sq: 0.3 },
      { y: 0.545, rx: 0.120, rz: 0.098, sq: 0.2 }
    ];
    if (isCop) { for (var ti = 0; ti < torsoSec.length; ti++) { torsoSec[ti].rx *= 1.10; torsoSec[ti].rz *= 1.22; } }
    var chest = node(hips, 0, 0.02, 0);
    mesh(loft(THREE, torsoSec, R, true, true), M.suit, chest, 0, 0, 0);

    // спортивные полосы / бронежилет
    if (!isCop) {
      // молния по центру куртки
      var zip = mesh(loft(THREE, [
        { y: 0.02, rx: 0.011, rz: 0.010 },
        { y: 0.50, rx: 0.011, rz: 0.010 }
      ], 6, true, true), M.stripe, chest, 0, 0, 0.128);
      zip.rotation.x = -0.06;
      // воротник
      mesh(loft(THREE, [
        { y: 0.50, rx: 0.125, rz: 0.100, sq: 0.2 },
        { y: 0.565, rx: 0.132, rz: 0.107, sq: 0.2 }
      ], R, false, false), M.suitDark, chest, 0, 0, 0);
    } else {
      mesh(loft(THREE, [
        { y: 0.06, rx: 0.196, rz: 0.152, sq: 0.35 },
        { y: 0.26, rx: 0.212, rz: 0.166, sq: 0.35 },
        { y: 0.44, rx: 0.222, rz: 0.170, sq: 0.4 },
        { y: 0.52, rx: 0.186, rz: 0.146, sq: 0.35 }
      ], R, true, true), M.vest, chest, 0, 0, 0);
      // жетон
      var badge = mesh(new THREE.CircleGeometry(0.035, 12), M.metal, chest, 0.10, 0.40, 0.152);
      badge.rotation.x = 0.06;
    }

    // --- шея и голова ---
    var neck = node(chest, 0, 0.545, 0);
    mesh(limb(THREE, 0.085, 0.060, 0.064, 10, 0.05), M.skin, neck, 0, 0.085, 0);
    var head = node(neck, 0, 0.105, 0);
    var detail = node(head, 0, 0, 0);   // мелкие детали лица — отключаются вдали

    var headGeo = deformedSphere(THREE, 0.138, 22, function (x, y, z) {
      var yy = y;
      // затылок шире, подбородок сужен
      var narrow = 1 - Math.max(0, -yy / 0.138) * 0.30;
      var back = z < 0 ? 1.05 : 1.0;
      // темя чуть приплюснуто
      var flat = 1 - Math.max(0, yy / 0.138) * 0.06;
      return [x * 0.95 * narrow, yy * 1.16 * flat, z * back * narrow * 1.02];
    });
    mesh(headGeo, M.skin, head, 0, 0, 0);

    // уши
    var earGeo = deformedSphere(THREE, 0.036, 10, function (x, y, z) { return [x * 0.35, y * 1.15, z * 0.85]; });
    mesh(earGeo, M.skin, detail, 0.128, -0.005, -0.004);
    mesh(earGeo, M.skin, detail, -0.128, -0.005, -0.004);

    // нос
    var noseGeo = deformedSphere(THREE, 0.032, 10, function (x, y, z) { return [x * 0.75, y * 0.95, z * 1.35]; });
    mesh(noseGeo, M.skin, detail, 0, -0.014, 0.124);

    // глаза
    var eyeGeo = new THREE.SphereGeometry(0.026, 12, 10);
    var irisGeo = new THREE.SphereGeometry(0.0135, 10, 8);
    var eyeL = mesh(eyeGeo, M.eye, head, 0.052, 0.040, 0.110);
    var eyeR = mesh(eyeGeo, M.eye, head, -0.052, 0.040, 0.110);
    mesh(irisGeo, M.iris, eyeL, 0, 0, 0.017);
    mesh(irisGeo, M.iris, eyeR, 0, 0, 0.017);

    // брови
    var browGeo = deformedSphere(THREE, 0.026, 8, function (x, y, z) { return [x * 1.5, y * 0.28, z * 0.45]; });
    var browL = mesh(browGeo, M.dark, detail, 0.054, 0.078, 0.116);
    var browR = mesh(browGeo, M.dark, detail, -0.054, 0.078, 0.116);
    browL.rotation.z = isCop ? -0.35 : -0.12;
    browR.rotation.z = isCop ? 0.35 : 0.12;

    // рот
    var mouthGeo = deformedSphere(THREE, 0.030, 10, function (x, y, z) { return [x * 1.35, y * 0.36, z * 0.30]; });
    var mouth = mesh(mouthGeo, M.mouth, head, 0, -0.064, 0.116);

    // усы у мента
    if (isCop) {
      var mus = deformedSphere(THREE, 0.030, 8, function (x, y, z) { return [x * 1.30, y * 0.30, z * 0.45]; });
      mesh(mus, M.dark, detail, 0, -0.040, 0.119);
      // фуражка
      var capGeo = loft(THREE, [
        { y: 0.00, rx: 0.143, rz: 0.141 },
        { y: 0.035, rx: 0.146, rz: 0.144 },
        { y: 0.078, rx: 0.138, rz: 0.136 },
        { y: 0.110, rx: 0.110, rz: 0.108 }
      ], R, false, true);
      mesh(capGeo, M.cap, head, 0, 0.060, 0);
      var visor = deformedSphere(THREE, 0.10, 12, function (x, y, z) {
        return [x * 1.05, y * 0.10, Math.max(0, z) * 1.05 + 0.02];
      });
      var v = mesh(visor, M.visor, head, 0, 0.058, 0.052);
      v.rotation.x = -0.22;
      var star = mesh(new THREE.CircleGeometry(0.022, 10), M.metal, detail, 0, 0.096, 0.134);
      star.rotation.x = 0.15;
    }

    // --- руки ---
    function arm(side) {
      var sh = node(chest, side * 0.196, 0.455, 0);
      sh.rotation.z = side * 0.10;
      mesh(limb(THREE, 0.30, 0.062, 0.050, 12), M.suit, sh, 0, 0, 0);
      if (!isCop) {
        var arS = mesh(loft(THREE, [
          { y: -0.03, rx: 0.007, rz: 0.017 },
          { y: -0.27, rx: 0.006, rz: 0.015 }
        ], 6, true, true), M.stripe, sh, side * 0.062, 0, 0);
      }
      var el = node(sh, 0, -0.30, 0);
      mesh(limb(THREE, 0.27, 0.050, 0.040, 12), isCop ? M.suit : M.suit, el, 0, 0, 0);
      var wr = node(el, 0, -0.27, 0);
      var handGeo = deformedSphere(THREE, 0.052, 10, function (x, y, z) { return [x * 0.62, y * 1.15, z * 0.95]; });
      mesh(handGeo, M.skin, wr, 0, -0.045, 0);
      return { shoulder: sh, elbow: el, wrist: wr };
    }
    var armL = arm(1), armR = arm(-1);

    // --- ноги ---
    function leg(side) {
      var hp = node(hips, side * 0.088, -0.06, 0);
      mesh(limb(THREE, 0.45, 0.086, 0.062, 12), M.pants, hp, 0, 0, 0);
      var kn = node(hp, 0, -0.45, 0);
      mesh(limb(THREE, 0.42, 0.062, 0.046, 12), M.pants, kn, 0, 0, 0);
      var an = node(kn, 0, -0.42, 0);
      // ботинок
      var shoeGeo = loft(THREE, [
        { y: 0.00, rx: 0.052, rz: 0.075, oz: 0.02, sq: 0.5 },
        { y: -0.035, rx: 0.056, rz: 0.098, oz: 0.035, sq: 0.6 },
        { y: -0.065, rx: 0.058, rz: 0.108, oz: 0.045, sq: 0.75 },
        { y: -0.078, rx: 0.052, rz: 0.104, oz: 0.045, sq: 0.85 }
      ], 12, true, true);
      mesh(shoeGeo, M.shoe, an, 0, 0, 0);
      return { hip: hp, knee: kn, ankle: an };
    }
    var legL = leg(1), legR = leg(-1);

    var ch = {
      root: root, hips: hips, chest: chest, neck: neck, head: head,
      armL: armL, armR: armR, legL: legL, legR: legR,
      mouth: mouth, browL: browL, browR: browR, eyeL: eyeL, eyeR: eyeR, detail: detail,
      materials: M, palette: pal, isCop: isCop,
      phase: Math.random() * 6.28, blink: 0, t: Math.random() * 10,
      baseHipY: 0.92
    };
    return ch;
  }

  // ---- анимация ---------------------------------------------------
  function update(ch, dt, st) {
    st = st || {};
    var speed = st.speed || 0;
    var run = Math.min(1, speed / 4.2);
    ch.t += dt;
    var stride = 2.6 + run * 1.5;
    ch.phase += dt * speed * stride;
    var p = ch.phase;
    var moving = Math.min(1, speed / 1.2);
    var amp = 0.5 + run * 0.75;

    // ноги
    var lf = Math.sin(p), rf = Math.sin(p + Math.PI);
    ch.legL.hip.rotation.x = -lf * 0.62 * amp * moving;
    ch.legR.hip.rotation.x = -rf * 0.62 * amp * moving;
    ch.legL.knee.rotation.x = Math.max(0, Math.sin(p - 1.05)) * 1.15 * amp * moving + 0.05;
    ch.legR.knee.rotation.x = Math.max(0, Math.sin(p + Math.PI - 1.05)) * 1.15 * amp * moving + 0.05;
    ch.legL.ankle.rotation.x = (0.12 + lf * 0.30 * amp) * moving;
    ch.legR.ankle.rotation.x = (0.12 + rf * 0.30 * amp) * moving;

    // руки
    var swing = 0.55 * amp * moving;
    ch.armL.shoulder.rotation.x = rf * swing;
    ch.armR.shoulder.rotation.x = lf * swing;
    ch.armL.shoulder.rotation.z = 0.10 + run * 0.06 - Math.max(0, rf) * 0.05;
    ch.armR.shoulder.rotation.z = -0.10 - run * 0.06 + Math.max(0, lf) * 0.05;
    var elbowBend = (0.25 + run * 0.85) * moving + 0.12;
    ch.armL.elbow.rotation.x = -elbowBend - Math.max(0, rf) * 0.35 * moving;
    ch.armR.elbow.rotation.x = -elbowBend - Math.max(0, lf) * 0.35 * moving;

    // корпус: покачивание, наклон вперёд на бегу, дыхание
    var bob = Math.abs(Math.sin(p)) * 0.035 * amp * moving;
    var breathe = Math.sin(ch.t * 1.7) * 0.008 * (1 - moving * 0.6);
    ch.hips.position.y = ch.baseHipY + bob + breathe + (st.crouch ? -0.18 : 0);
    ch.hips.rotation.y = Math.sin(p) * 0.10 * moving;
    ch.hips.rotation.x = run * 0.22 + moving * 0.05;
    ch.chest.rotation.y = -Math.sin(p) * 0.14 * moving;
    ch.chest.rotation.z = Math.sin(p) * 0.05 * moving;
    ch.chest.rotation.x = -run * 0.10 + Math.sin(ch.t * 1.7) * 0.012;

    // прыжок
    if (st.airborne) {
      ch.legL.hip.rotation.x = -0.55; ch.legR.hip.rotation.x = 0.25;
      ch.legL.knee.rotation.x = 0.95; ch.legR.knee.rotation.x = 0.35;
      ch.armL.shoulder.rotation.x = -1.1; ch.armR.shoulder.rotation.x = -1.1;
      ch.armL.elbow.rotation.x = -0.5; ch.armR.elbow.rotation.x = -0.5;
      ch.hips.rotation.x = -0.10;
    }

    // голова: держим горизонт + смотрим в сторону цели
    ch.neck.rotation.x = -ch.chest.rotation.x * 0.7 - run * 0.12 + (st.headPitch || 0);
    ch.head.rotation.y = (st.headYaw || 0) - ch.chest.rotation.y * 0.5;
    ch.head.rotation.z = -ch.chest.rotation.z * 0.6;

    // моргание
    ch.blink -= dt;
    if (ch.blink < 0) ch.blink = 2 + Math.random() * 4;
    var blinking = ch.blink < 0.12 ? 1 : 0;
    var ey = blinking ? 0.15 : 1;
    ch.eyeL.scale.y = ch.eyeR.scale.y = ey;

    // эмоции
    if (st.scared) {
      ch.mouth.scale.set(0.8, 2.2, 1.0);
      ch.browL.position.y = ch.browR.position.y = 0.082;
    } else if (st.angry) {
      ch.mouth.scale.set(1.15, 0.7, 1.0);
      ch.browL.rotation.z = -0.5; ch.browR.rotation.z = 0.5;
    } else {
      ch.mouth.scale.set(1, 1 + Math.sin(ch.t * 2.2) * 0.05, 1);
      ch.browL.position.y = ch.browR.position.y = 0.070;
    }
  }

  function setDetail(ch, on) {
    if (ch.detail && ch.detail.visible !== on) ch.detail.visible = on;
    if (ch.eyeL) { ch.eyeL.visible = on; ch.eyeR.visible = on; ch.mouth.visible = on; }
  }

  global.CHAR = { build: build, update: update, setDetail: setDetail, SKINS: SKINS, COP: COP, loft: loft, limb: limb };
})(window);
