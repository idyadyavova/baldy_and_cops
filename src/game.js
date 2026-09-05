/* =====================================================================
   game.js — игровая логика: уровни из карт оригинала, игрок, менты
   с поиском пути (BFS), камера, интерфейс, мини-карта.
   ===================================================================== */
(function (global) {
  'use strict';
  var THREE = global.THREE;
  var $ = function (id) { return document.getElementById(id); };

  // ------------------------------------------------------------------
  //  Настройки и сохранения
  // ------------------------------------------------------------------
  var CFG = {
    difficulty: 1, skin: 0, quality: 'high',
    rays: 1, ao: 1, bloom: 1, fps: 0
  };
  function loadCfg() {
    try {
      var j = JSON.parse(localStorage.getItem('baldy3d_cfg') || '{}');
      for (var k in j) if (k in CFG) CFG[k] = j[k];
    } catch (e) { }
  }
  function saveCfg() { try { localStorage.setItem('baldy3d_cfg', JSON.stringify(CFG)); } catch (e) { } }
  function bestTime(d) { try { return parseFloat(localStorage.getItem('baldy3d_best_' + d) || '0') || 0; } catch (e) { return 0; } }
  function setBest(d, t) { try { localStorage.setItem('baldy3d_best_' + d, String(t)); } catch (e) { } }

  var DIFF = {
    1: { map: '1', name: 'Простой', cops: 2, copSpeed: 2.10, vision: 10, repath: 1.5, coins: 8, sun: 0.32, azim: 0.85, cloud: 0.56 },
    2: { map: '2', name: 'Средний', cops: 3, copSpeed: 2.50, vision: 12, repath: 1.1, coins: 12, sun: 0.085, azim: 2.35, cloud: 0.47 },
    3: { map: '3', name: 'Сложный', cops: 5, copSpeed: 2.72, vision: 14, repath: 0.8, coins: 16, sun: -0.40, azim: 1.6, cloud: 0.64 }
  };

  // ------------------------------------------------------------------
  //  Состояние
  // ------------------------------------------------------------------
  var S = {
    screen: 'load', eng: null, tex: null, detail: null, waterNrm: null,
    level: null, levelDiff: 0, levelSkin: -1,
    player: null, cops: [], coins: [], coinMesh: null,
    time: 0, coinsGot: 0, paused: false, alive: true, won: false,
    startGrace: 0, alertLevel: 0, seenAny: false,
    menuAngle: 0, fps: 60, fpsAcc: 0, fpsCount: 0, adaptTimer: 0,
    depthMatObj: null, blockMat: null, folMat: null, lanternLights: []
  };

  var cam = { yaw: 0.6, pitch: 0.12, dist: 5.0, curDist: 5.0, minDist: 1.35, pos: new THREE.Vector3(), shake: 0 };
  var input = {
    mx: 0, my: 0, run: false, jump: false,
    stickId: null, stickX: 0, stickY: 0, stickCX: 0, stickCY: 0,
    lookId: null, lookX: 0, lookY: 0, keys: {}
  };

  // ------------------------------------------------------------------
  //  Экраны
  // ------------------------------------------------------------------
  var LAYERS = ['loading', 'menu', 'charPanel', 'gfxPanel', 'helpPanel', 'hud', 'pausePanel', 'endPanel'];
  function show() {
    var on = Array.prototype.slice.call(arguments);
    LAYERS.forEach(function (id) {
      var el = $(id); if (!el) return;
      el.classList.toggle('on', on.indexOf(id) >= 0);
    });
  }
  function fmtTime(t) {
    var m = Math.floor(t / 60), s = Math.floor(t % 60);
    return (m < 10 ? '0' : '') + m + ':' + (s < 10 ? '0' : '') + s;
  }

  // ------------------------------------------------------------------
  //  Загрузка
  // ------------------------------------------------------------------
  function boot() {
    loadCfg();
    var canvas = $('gl');
    if (!canvas.getContext('webgl2')) {
      $('loadTxt').innerHTML = 'Нужен браузер с WebGL 2.<br>Открой в Chrome или Safari посвежее.';
      return;
    }
    S.eng = global.ENGINE.create(THREE, canvas, CFG.quality);
    applyGfx();
    resize();
    window.addEventListener('resize', resize);
    window.addEventListener('orientationchange', function () { setTimeout(resize, 250); });
    bindUI();

    var size = global.ENGINE.QUALITY[CFG.quality].texSize;
    var builder = global.TEX.createBuilder(THREE, size);
    $('loadTxt').textContent = 'генерация материалов…';
    function step() {
      var t0 = performance.now();
      while (builder.done < builder.total && performance.now() - t0 < 13) builder.step();
      var p = builder.done / builder.total;
      $('loadBar').style.width = Math.round(p * 82) + '%';
      if (builder.done < builder.total) requestAnimationFrame(step);
      else {
        S.tex = builder.finish();
        S.detail = global.TEX.detailNormal(THREE, 128);
        S.waterNrm = global.TEX.waterNormal(THREE, 256);
        S.eng.common.uDetail.value = S.detail;
        $('loadTxt').textContent = 'строим лабиринт…';
        $('loadBar').style.width = '88%';
        requestAnimationFrame(function () {
          buildLevel(CFG.difficulty);
          $('loadBar').style.width = '100%';
          setTimeout(function () {
            show('menu');
            S.screen = 'menu';
            updateMenuTexts();
            requestAnimationFrame(frame);
          }, 180);
        });
      }
    }
    requestAnimationFrame(step);
  }

  function resize() {
    var w = window.innerWidth, h = window.innerHeight;
    var dpr = Math.min(window.devicePixelRatio || 1, CFG.quality === 'low' ? 1.0 : 1.6);
    var cv = $('gl');
    cv.width = Math.floor(w * dpr); cv.height = Math.floor(h * dpr);
    cv.style.width = w + 'px'; cv.style.height = h + 'px';
    if (S.eng) S.eng.resize(cv.width, cv.height, 1);
    if (S.eng) { S.eng.camera.aspect = w / h; S.eng.camera.updateProjectionMatrix(); }
  }

  function applyGfx() {
    var e = S.eng; if (!e) return;
    e.setQuality(CFG.quality);
    e.post.settings.rays = !!CFG.rays && global.ENGINE.QUALITY[CFG.quality].rays;
    e.post.settings.ssao = !!CFG.ao && global.ENGINE.QUALITY[CFG.quality].ssao;
    e.post.settings.bloom = !!CFG.bloom;
    if (S.blockMat) S.blockMat.uniforms.uPOM.value = e.Q.pom;
    if (S.blockMat) S.blockMat.uniforms.uDetailAmt.value = e.Q.detail;
    $('fps').style.display = CFG.fps ? 'block' : 'none';
  }

  // освобождаем видеопамять при пересборке уровня/персонажей
  function disposeObject(root) {
    root.traverse(function (o) {
      if (o.isMesh || o.isInstancedMesh) {
        if (o.geometry) o.geometry.dispose();
        var m = o.material;
        if (Array.isArray(m)) m.forEach(function (mm) { mm.dispose(); });
        else if (m && m.dispose && m !== S.blockMat && m !== S.folMat) m.dispose();
      }
    });
  }

  // ------------------------------------------------------------------
  //  Построение уровня
  // ------------------------------------------------------------------
  function buildLevel(diff) {
    var eng = S.eng;
    // очистка предыдущего
    if (S.level) {
      S.level.objects.forEach(function (o) { eng.scene.remove(o); disposeObject(o); });
    }
    S.lanternLights.length = 0;

    var D = DIFF[diff];
    var map = global.MAPS[D.map];
    var B = global.WORLD.init(global.TEX.TILES);
    var world = global.WORLD.generate(THREE, map, 1000 + diff * 77, {});
    var mesh = global.WORLD.buildMesh(THREE, world.vol, B, true);
    global.WORLD.addGrassTufts(mesh.foliage, world, global.TEX.TILES, eng.Q.grass);

    if (!S.blockMat) {
      S.blockMat = global.MAT.makeBlockMaterial(THREE, eng.common, S.tex);
      S.folMat = global.MAT.makeFoliageMaterial(THREE, eng.common, S.tex);
      S.folMat.uniforms.uPOM.value = 0;
      S.depthMatObj = global.MAT.makeObjectDepthMaterial(THREE);
      S.depthBlock = global.MAT.makeBlockDepthMaterial(THREE, eng.common, S.tex, false);
      S.depthFol = global.MAT.makeBlockDepthMaterial(THREE, eng.common, S.tex, true);
    }
    S.blockMat.uniforms.uPOM.value = eng.Q.pom;
    S.blockMat.uniforms.uDetailAmt.value = eng.Q.detail;

    var objects = [];
    var solid = new THREE.Mesh(mesh.solid.geometry(THREE), S.blockMat);
    solid.userData.depthMat = S.depthBlock;
    solid.frustumCulled = false;
    eng.scene.add(solid); objects.push(solid);
    var fol = new THREE.Mesh(mesh.foliage.geometry(THREE), S.folMat);
    fol.userData.depthMat = S.depthFol;
    fol.frustumCulled = false;
    eng.scene.add(fol); objects.push(fol);

    // вода вокруг острова
    var wsize = Math.max(world.sx, world.sz) * 3;
    var water = global.WATER.create(THREE, eng.common, eng.sky.uniforms, wsize, global.WORLD.WATER, { normalMap: S.waterNrm });
    water.mesh.position.x = world.sx / 2; water.mesh.position.z = world.sz / 2;
    eng.scene.add(water.mesh); objects.push(water.mesh);

    var CELL = global.WORLD.CELL, BASE = global.WORLD.BASE;
    function cellToWorld(c, r) {
      return { x: world.mazeX0 + c * CELL + CELL / 2, z: world.mazeZ0 + r * CELL + CELL / 2 };
    }

    // выход
    var exitCell = world.exitCell || [world.cells.w - 2, world.cells.h - 2];
    var ex = cellToWorld(exitCell[0], exitCell[1]);
    var exitObj = global.PROPS.makeExit(THREE, eng.common, S.depthMatObj);
    exitObj.group.position.set(ex.x, BASE + 1, ex.z);
    // разворачиваем портал к открытой стороне
    var openDir = 0;
    var dirs = [[0, -1, 0], [1, 0, Math.PI / 2], [0, 1, Math.PI], [-1, 0, -Math.PI / 2]];
    for (var di = 0; di < dirs.length; di++) {
      var cc = exitCell[0] + dirs[di][0], rr = exitCell[1] + dirs[di][1];
      if (rr >= 0 && rr < world.cells.h && cc >= 0 && cc < world.cells.w && !world.wallGrid[rr][cc]) { openDir = dirs[di][2]; break; }
    }
    exitObj.group.rotation.y = openDir;
    eng.scene.add(exitObj.group); objects.push(exitObj.group);

    // монеты в тупиках и случайных клетках
    var open = [];
    for (var r2 = 0; r2 < world.cells.h; r2++) for (var c2 = 0; c2 < world.cells.w; c2++) {
      if (!world.wallGrid[r2][c2]) open.push([c2, r2]);
    }
    var rnd = global.NZ.mulberry32(diff * 999 + 7);
    var pc = world.playerCell || open[0];
    var coinCells = [];
    var pool = open.slice();
    for (var i = pool.length - 1; i > 0; i--) { var j = Math.floor(rnd() * (i + 1)); var t = pool[i]; pool[i] = pool[j]; pool[j] = t; }
    for (var k = 0; k < pool.length && coinCells.length < D.coins; k++) {
      var cc2 = pool[k];
      var dx = cc2[0] - pc[0], dz = cc2[1] - pc[1];
      if (dx * dx + dz * dz < 16) continue;
      if (cc2[0] === exitCell[0] && cc2[1] === exitCell[1]) continue;
      coinCells.push(cc2);
    }
    S.coins = coinCells.map(function (c) {
      var w = cellToWorld(c[0], c[1]);
      return { x: w.x, y: BASE + 1.55, z: w.z, taken: false, spin: Math.random() * 6.28 };
    });
    var coinObj = global.PROPS.makeCoins(THREE, eng.common, S.coins, S.depthMatObj);
    eng.scene.add(coinObj.mesh); objects.push(coinObj.mesh);
    S.coinMesh = coinObj.mesh;

    // фонари: один инстансовый меш на весь лабиринт
    var lanterns = [];
    var step = 5;
    for (var lr = 1; lr < world.cells.h - 1; lr += step) {
      for (var lc = 1; lc < world.cells.w - 1; lc += step) {
        if (world.wallGrid[lr][lc]) continue;
        var lw = cellToWorld(lc, lr);
        var rot = ((lr + lc) % 4) * Math.PI / 2;
        var ax = Math.sin(rot) * 0.42, az = Math.cos(rot) * 0.42;
        lanterns.push({ x: lw.x + 0.55, y: BASE + 1, z: lw.z + 0.55, rot: rot,
                        lx: lw.x + 0.55 + ax, ly: BASE + 3.35, lz: lw.z + 0.55 + az });
      }
    }
    if (lanterns.length) {
      global.PROPS.makeLanterns(THREE, eng.common, lanterns, S.depthMatObj).forEach(function (m) {
        eng.scene.add(m); objects.push(m);
      });
    }

    S.level = {
      world: world, objects: objects, water: water, exit: { x: ex.x, z: ex.z, cell: exitCell, obj: exitObj },
      cellToWorld: cellToWorld, lanterns: lanterns, diff: diff,
      explored: new Uint8Array(world.cells.w * world.cells.h)
    };
    S.levelDiff = diff;

    // погода и время суток
    eng.setTimeOfDay(D.sun, D.azim, D.cloud);
    eng.shadowRadius = 30;

    spawnActors();
  }

  function spawnActors() {
    var eng = S.eng, L = S.level, world = L.world, D = DIFF[L.diff];
    var BASE = global.WORLD.BASE;
    // удаляем старых
    if (S.player) { eng.scene.remove(S.player.char.root); disposeObject(S.player.char.root); }
    S.cops.forEach(function (c) { eng.scene.remove(c.char.root); disposeObject(c.char.root); });
    S.cops = [];

    var pc = world.playerCell || [1, 1];
    var pw = L.cellToWorld(pc[0], pc[1]);
    var pchar = global.CHAR.build(THREE, eng.common, CFG.skin, false, S.depthMatObj);
    eng.scene.add(pchar.root);
    S.player = {
      char: pchar, x: pw.x, z: pw.z, y: BASE + 1, vy: 0, vx: 0, vz: 0,
      yaw: 0, onGround: true, stamina: 1, speed: 0, stepTimer: 0, cell: pc.slice()
    };
    S.levelSkin = CFG.skin;

    // менты: сначала точки 'P' из карты, потом дальние клетки
    var spots = (world.copCells || []).slice();
    var open = [];
    for (var r = 0; r < world.cells.h; r++) for (var c = 0; c < world.cells.w; c++) {
      if (!world.wallGrid[r][c]) {
        var dx = c - pc[0], dz = r - pc[1];
        if (dx * dx + dz * dz > (D.cops > 3 ? 200 : 110)) open.push([c, r]);
      }
    }
    var rnd = global.NZ.mulberry32(4242 + L.diff);
    while (spots.length < D.cops && open.length) {
      spots.push(open[Math.floor(rnd() * open.length)]);
    }
    for (var i = 0; i < D.cops; i++) {
      var sc = spots[i % spots.length];
      var w = L.cellToWorld(sc[0], sc[1]);
      var cchar = global.CHAR.build(THREE, eng.common, 0, true, S.depthMatObj);
      cchar.root.position.set(w.x, BASE + 1, w.z);
      eng.scene.add(cchar.root);
      S.cops.push({
        char: cchar, x: w.x, z: w.z, y: BASE + 1, yaw: 0, speed: 0,
        path: [], pathIdx: 0, repathTimer: rnd() * 0.5, state: 'patrol',
        target: null, lastKnown: null, alert: 0, whistle: 0, cell: sc.slice()
      });
    }
  }

  // ------------------------------------------------------------------
  //  Геометрия мира: столкновения и клетки
  // ------------------------------------------------------------------
  function solidAt(x, z) {
    var L = S.level; if (!L) return false;
    var w = L.world;
    var bx = Math.floor(x), bz = Math.floor(z);
    if (bx < 0 || bz < 0 || bx >= w.sx || bz >= w.sz) return true;
    return w.vol.get(bx, global.WORLD.BASE + 1, bz) !== 0;
  }
  function blocked(x, z, r) {
    return solidAt(x - r, z - r) || solidAt(x + r, z - r) || solidAt(x - r, z + r) || solidAt(x + r, z + r);
  }
  function cellOf(x, z) {
    var L = S.level, CELL = global.WORLD.CELL;
    return [Math.floor((x - L.world.mazeX0) / CELL), Math.floor((z - L.world.mazeZ0) / CELL)];
  }
  function isWallCell(c, r) {
    var L = S.level;
    if (r < 0 || c < 0 || r >= L.world.cells.h || c >= L.world.cells.w) return true;
    return L.world.wallGrid[r][c];
  }

  // поиск в ширину — как в оригинальной игре менты искали игрока
  function bfsPath(from, to) {
    var L = S.level, W = L.world.cells.w, H = L.world.cells.h;
    if (isWallCell(to[0], to[1])) return null;
    var prev = new Int32Array(W * H).fill(-1);
    var seen = new Uint8Array(W * H);
    var q = new Int32Array(W * H), qs = 0, qe = 0;
    var start = from[1] * W + from[0];
    if (from[0] < 0 || from[1] < 0 || from[0] >= W || from[1] >= H) return null;
    q[qe++] = start; seen[start] = 1;
    var goal = to[1] * W + to[0];
    var found = false;
    while (qs < qe) {
      var cur = q[qs++];
      if (cur === goal) { found = true; break; }
      var cx = cur % W, cy = (cur / W) | 0;
      for (var d = 0; d < 4; d++) {
        var nx = cx + (d === 0 ? 1 : d === 1 ? -1 : 0);
        var ny = cy + (d === 2 ? 1 : d === 3 ? -1 : 0);
        if (nx < 0 || ny < 0 || nx >= W || ny >= H) continue;
        var ni = ny * W + nx;
        if (seen[ni] || L.world.wallGrid[ny][nx]) continue;
        seen[ni] = 1; prev[ni] = cur; q[qe++] = ni;
      }
    }
    if (!found) return null;
    var path = [], node = goal;
    while (node !== start && node >= 0) { path.push([node % W, (node / W) | 0]); node = prev[node]; }
    path.reverse();
    return path;
  }

  // прямая видимость по клеткам
  function lineOfSight(a, b) {
    var x0 = a[0], y0 = a[1], x1 = b[0], y1 = b[1];
    var dx = Math.abs(x1 - x0), dy = Math.abs(y1 - y0);
    var sx = x0 < x1 ? 1 : -1, sy = y0 < y1 ? 1 : -1;
    var err = dx - dy, guard = 0;
    while (guard++ < 200) {
      if (x0 === x1 && y0 === y1) return true;
      if (isWallCell(x0, y0) && !(x0 === a[0] && y0 === a[1])) return false;
      var e2 = 2 * err;
      if (e2 > -dy) { err -= dy; x0 += sx; }
      if (e2 < dx) { err += dx; y0 += sy; }
    }
    return false;
  }

  // ------------------------------------------------------------------
  //  Управление
  // ------------------------------------------------------------------
  function bindUI() {
    // кнопки меню
    $('btnPlay').onclick = function () { goFullscreen(); startGame(); };
    $('btnChar').onclick = function () { show('charPanel'); S.charPreview = true; S.previewAngle = 0; };
    $('charOk').onclick = function () { S.charPreview = false; closePanel(); };
    $('btnGfx').onclick = function () { show('gfxPanel'); syncGfxUI(); };
    $('gfxOk').onclick = closePanel;
    $('btnHelp').onclick = function () { show('helpPanel'); };
    $('helpOk').onclick = closePanel;
    $('btnDiff').onclick = function () {
      CFG.difficulty = CFG.difficulty % 3 + 1; saveCfg(); updateMenuTexts();
      buildLevel(CFG.difficulty);
    };
    $('btnPause').onclick = function () { pause(true); };
    $('pResume').onclick = function () { pause(false); };
    $('pRestart').onclick = function () { startGame(); };
    $('pMenu').onclick = function () { toMenu(); };
    $('pGfx').onclick = function () { show('gfxPanel'); syncGfxUI(); };
    $('endAgain').onclick = function () { startGame(); };
    $('endMenu').onclick = function () { toMenu(); };

    // выбор персонажа
    var list = $('skinList');
    global.CHAR.SKINS.forEach(function (sk, i) {
      var d = document.createElement('div');
      d.className = 'skin' + (i === CFG.skin ? ' sel' : '');
      d.style.background = 'linear-gradient(160deg,#' + ('000000' + sk.suit.toString(16)).slice(-6) +
        ',#' + ('000000' + sk.pants.toString(16)).slice(-6) + ')';
      d.textContent = sk.name;
      d.onclick = function () {
        CFG.skin = i; saveCfg();
        Array.prototype.forEach.call(list.children, function (c, ci) { c.classList.toggle('sel', ci === i); });
        $('skinName').textContent = sk.name;
        if (S.level) spawnActors();
      };
      list.appendChild(d);
    });

    // графика
    function seg(id, get, set) {
      var box = $(id);
      Array.prototype.forEach.call(box.children, function (b) {
        b.onclick = function () {
          set(b.dataset.q || parseInt(b.dataset.v, 10));
          saveCfg(); syncGfxUI(); applyGfx();
        };
      });
    }
    seg('segQ', null, function (v) {
      CFG.quality = v;
      var need = global.ENGINE.QUALITY[v].texSize !== (S.tex ? S.tex.size : 0);
      if (need) {
        // текстуры другого разрешения — пересоберём на лету
        var b = global.TEX.createBuilder(THREE, global.ENGINE.QUALITY[v].texSize);
        while (b.done < b.total) b.step();
        var t = b.finish();
        S.tex = t;
        if (S.blockMat) {
          [S.blockMat, S.folMat, S.depthBlock, S.depthFol].forEach(function (m) {
            if (!m) return;
            if (m.uniforms.uAlbedo) m.uniforms.uAlbedo.value = t.albedo;
            if (m.uniforms.uNormalMap) m.uniforms.uNormalMap.value = t.normal;
            if (m.uniforms.uMatMap) m.uniforms.uMatMap.value = t.matmap;
            if (m.uniforms.uParaScale) m.uniforms.uParaScale.value.set(t.paraScales.subarray(0, 24));
          });
        }
      }
      if (S.level && S.screen === 'menu') buildLevel(CFG.difficulty);   // густота травы зависит от качества
    });
    seg('segRays', null, function (v) { CFG.rays = v; });
    seg('segAO', null, function (v) { CFG.ao = v; });
    seg('segBloom', null, function (v) { CFG.bloom = v; });
    seg('segFps', null, function (v) { CFG.fps = v; });

    // джойстик
    var stick = $('stick'), knob = stick.querySelector('i');
    function stickStart(e) {
      var t = e.changedTouches ? e.changedTouches[0] : e;
      input.stickId = e.changedTouches ? t.identifier : 'mouse';
      var r = stick.getBoundingClientRect();
      input.stickCX = r.left + r.width / 2; input.stickCY = r.top + r.height / 2;
      stickMove(e);
    }
    function stickMove(e) {
      var t = null;
      if (e.changedTouches) {
        for (var i = 0; i < e.changedTouches.length; i++)
          if (e.changedTouches[i].identifier === input.stickId) t = e.changedTouches[i];
      } else t = e;
      if (!t) return;
      var dx = t.clientX - input.stickCX, dy = t.clientY - input.stickCY;
      var len = Math.sqrt(dx * dx + dy * dy), max = 52;
      if (len > max) { dx = dx / len * max; dy = dy / len * max; }
      input.stickX = dx / max; input.stickY = dy / max;
      knob.style.transform = 'translate(' + dx + 'px,' + dy + 'px)';
    }
    function stickEnd() {
      input.stickId = null; input.stickX = 0; input.stickY = 0;
      knob.style.transform = '';
    }
    stick.addEventListener('touchstart', function (e) { e.preventDefault(); stickStart(e); }, { passive: false });
    stick.addEventListener('touchmove', function (e) { e.preventDefault(); stickMove(e); }, { passive: false });
    stick.addEventListener('touchend', stickEnd);
    stick.addEventListener('touchcancel', stickEnd);
    stick.addEventListener('mousedown', function (e) { e.preventDefault(); stickStart(e); });
    window.addEventListener('mousemove', function (e) { if (input.stickId === 'mouse') stickMove(e); });
    window.addEventListener('mouseup', function () { if (input.stickId === 'mouse') stickEnd(); });

    // камера пальцем по правой половине
    var cv = $('gl');
    function lookStart(e) {
      for (var i = 0; i < e.changedTouches.length; i++) {
        var t = e.changedTouches[i];
        if (t.clientX < window.innerWidth * 0.42) continue;
        if (input.lookId !== null) continue;
        input.lookId = t.identifier; input.lookX = t.clientX; input.lookY = t.clientY;
      }
    }
    function lookMove(e) {
      for (var i = 0; i < e.changedTouches.length; i++) {
        var t = e.changedTouches[i];
        if (t.identifier !== input.lookId) continue;
        var dx = t.clientX - input.lookX, dy = t.clientY - input.lookY;
        input.lookX = t.clientX; input.lookY = t.clientY;
        cam.yaw -= dx * 0.0055;
        cam.pitch = Math.max(-0.45, Math.min(0.85, cam.pitch + dy * 0.0042));
      }
    }
    function lookEnd(e) {
      for (var i = 0; i < e.changedTouches.length; i++)
        if (e.changedTouches[i].identifier === input.lookId) input.lookId = null;
    }
    cv.addEventListener('touchstart', function (e) { e.preventDefault(); global.SFX.resume(); lookStart(e); }, { passive: false });
    cv.addEventListener('touchmove', function (e) { e.preventDefault(); lookMove(e); }, { passive: false });
    cv.addEventListener('touchend', lookEnd);
    cv.addEventListener('touchcancel', lookEnd);
    var dragging = false, lx = 0, ly = 0;
    cv.addEventListener('mousedown', function (e) { dragging = true; lx = e.clientX; ly = e.clientY; global.SFX.resume(); });
    window.addEventListener('mouseup', function () { dragging = false; });
    window.addEventListener('mousemove', function (e) {
      if (!dragging) return;
      cam.yaw -= (e.clientX - lx) * 0.005;
      cam.pitch = Math.max(-0.45, Math.min(0.85, cam.pitch + (e.clientY - ly) * 0.004));
      lx = e.clientX; ly = e.clientY;
    });

    // кнопки действий
    function hold(el, on, off) {
      el.addEventListener('touchstart', function (e) { e.preventDefault(); el.classList.add('on'); on(); }, { passive: false });
      el.addEventListener('touchend', function (e) { e.preventDefault(); el.classList.remove('on'); if (off) off(); }, { passive: false });
      el.addEventListener('mousedown', function (e) { e.preventDefault(); el.classList.add('on'); on(); });
      el.addEventListener('mouseup', function () { el.classList.remove('on'); if (off) off(); });
      el.addEventListener('mouseleave', function () { el.classList.remove('on'); if (off) off(); });
    }
    hold($('btnJump'), function () { input.jump = true; });
    hold($('btnRun'), function () { input.run = true; }, function () { input.run = false; });

    // клавиатура
    window.addEventListener('keydown', function (e) {
      input.keys[e.code] = true;
      if (e.code === 'Escape') { if (S.screen === 'game') pause(!S.paused); }
      if (e.code === 'Space') { input.jump = true; e.preventDefault(); }
      global.SFX.resume();
    });
    window.addEventListener('keyup', function (e) { input.keys[e.code] = false; });
    window.addEventListener('blur', function () { if (S.screen === 'game' && !S.paused) pause(true); });
    document.addEventListener('visibilitychange', function () {
      if (document.hidden && S.screen === 'game' && !S.paused) pause(true);
    });
  }

  function syncGfxUI() {
    function mark(id, val) {
      var box = $(id); if (!box) return;
      Array.prototype.forEach.call(box.children, function (b) {
        var v = b.dataset.q !== undefined ? b.dataset.q : parseInt(b.dataset.v, 10);
        b.classList.toggle('sel', v === val || String(v) === String(val));
      });
    }
    mark('segQ', CFG.quality); mark('segRays', CFG.rays); mark('segAO', CFG.ao);
    mark('segBloom', CFG.bloom); mark('segFps', CFG.fps);
  }

  function updateMenuTexts() {
    $('diffVal').textContent = DIFF[CFG.difficulty].name;
    $('gfxVal').textContent = { low: 'Низкая', medium: 'Средняя', high: 'Высокая', ultra: 'Ультра' }[CFG.quality];
    var b = bestTime(CFG.difficulty);
    $('bestHint').innerHTML = b > 0
      ? 'Лучшее время (' + DIFF[CFG.difficulty].name.toLowerCase() + '): <b style="color:var(--gold)">' + fmtTime(b) + '</b>'
      : 'Убегай от ментов и найди светящийся выход';
    $('skinName').textContent = global.CHAR.SKINS[CFG.skin].name;
  }

  // ------------------------------------------------------------------
  //  Ход игры
  // ------------------------------------------------------------------
  function goFullscreen() {
    var el = document.documentElement;
    try {
      if (!document.fullscreenElement && el.requestFullscreen) el.requestFullscreen({ navigationUI: 'hide' });
      else if (!document.webkitFullscreenElement && el.webkitRequestFullscreen) el.webkitRequestFullscreen();
      if (screen.orientation && screen.orientation.lock) screen.orientation.lock('portrait').catch(function () { });
    } catch (e) { }
  }

  function startGame() {
    if (S.levelDiff !== CFG.difficulty) buildLevel(CFG.difficulty);
    else spawnActors();
    S.time = 0; S.coinsGot = 0; S.alive = true; S.won = false; S.paused = false;
    S.startGrace = 3.0; S.alertLevel = 0; S.seenAny = false;
    S.coins.forEach(function (c) { c.taken = false; });
    if (S.coinMesh) S.coinMesh.count = S.coins.length;
    cam.pitch = 0.12; cam.curDist = cam.dist;
    (function () {
      // ищем направление, где и впереди свободно, и сзади есть место для камеры
      var pc = S.player.cell;
      var dirs = [[0, 1, 0], [1, 0, Math.PI / 2], [0, -1, Math.PI], [-1, 0, -Math.PI / 2]];
      function openLen(dx, dz) {
        var n = 0;
        for (var k = 1; k <= 6; k++) { if (isWallCell(pc[0] + dx * k, pc[1] + dz * k)) break; n++; }
        return n;
      }
      var best = 0, bestScore = -1;
      for (var i = 0; i < 4; i++) {
        var f = openLen(dirs[i][0], dirs[i][1]);
        var b = openLen(-dirs[i][0], -dirs[i][1]);
        var sc = Math.min(b, 3) * 3.0 + f * 0.6;
        if (sc > bestScore) { bestScore = sc; best = dirs[i][2]; }
      }
      cam.yaw = best;
      S.player.yaw = best;
    })();
    S.screen = 'game';
    show('hud');
    global.SFX.init(); global.SFX.resume(); global.SFX.ambient(true);
    toast('НАЙДИ ВЫХОД!');
  }
  function toMenu() {
    S.screen = 'menu'; S.paused = false;
    if (S.player) { S.player.char.root.visible = true; S.fpMode = false; }
    global.SFX.siren(false); global.SFX.ambient(false);
    updateMenuTexts();
    show('menu');
  }
  function closePanel() {
    if (S.screen === 'game') show(S.paused ? 'pausePanel' : 'hud');
    else if (S.screen === 'end') show('endPanel');
    else show('menu');
  }
  function pause(on) {
    S.paused = on;
    show(on ? 'pausePanel' : 'hud');
    if (on) global.SFX.siren(false);
  }
  var toastTimer = 0;
  function toast(txt) {
    var el = $('toast');
    el.textContent = txt;
    el.style.opacity = 1; el.style.transform = 'translateY(0)';
    toastTimer = 1.8;
  }

  function endGame(win) {
    S.alive = !win ? false : true;
    S.won = win;
    S.screen = 'end';
    global.SFX.siren(false); global.SFX.ambient(false);
    if (win) global.SFX.win(); else global.SFX.lose();
    $('endTitle').textContent = win ? 'ПОБЕДА!' : 'ПОЙМАЛИ!';
    $('endTitle').style.color = win ? 'var(--green)' : 'var(--red)';
    $('endSub').textContent = win ? 'Ты нашёл выход и ушёл от ментов' : 'Мент схватил тебя за шиворот';
    $('endTime').textContent = fmtTime(S.time);
    $('endCoins').textContent = S.coinsGot + ' / ' + S.coins.length;
    var b = bestTime(CFG.difficulty);
    if (win && (b === 0 || S.time < b)) { setBest(CFG.difficulty, S.time); b = S.time; $('endSub').textContent = 'Новый рекорд!'; }
    $('endBest').textContent = b > 0 ? fmtTime(b) : '—';
    show('endPanel');
  }

  // ------------------------------------------------------------------
  //  Обновление игрока
  // ------------------------------------------------------------------
  var _fwd = new THREE.Vector3(), _side = new THREE.Vector3();
  function updatePlayer(dt) {
    var p = S.player, BASE = global.WORLD.BASE;
    var ix = input.stickX, iz = input.stickY;
    if (input.keys['KeyW'] || input.keys['ArrowUp']) iz -= 1;
    if (input.keys['KeyS'] || input.keys['ArrowDown']) iz += 1;
    if (input.keys['KeyA'] || input.keys['ArrowLeft']) ix -= 1;
    if (input.keys['KeyD'] || input.keys['ArrowRight']) ix += 1;
    var len = Math.sqrt(ix * ix + iz * iz);
    if (len > 1) { ix /= len; iz /= len; len = 1; }
    var run = (input.run || input.keys['ShiftLeft'] || input.keys['ShiftRight']) && p.stamina > 0.02 && len > 0.1;

    // направление относительно камеры:
    //   вперёд  F = (sin yaw, cos yaw)   — камера стоит позади игрока
    //   вправо  R = (-cos yaw, sin yaw)
    var cy = Math.cos(cam.yaw), sy = Math.sin(cam.yaw);
    var dirX = -iz * sy - ix * cy;
    var dirZ = -iz * cy + ix * sy;

    var maxSpeed = run ? 5.6 : 3.35;
    var accel = p.onGround ? 24 : 9;
    var tx = dirX * maxSpeed, tz = dirZ * maxSpeed;
    p.vx += (tx - p.vx) * Math.min(1, accel * dt);
    p.vz += (tz - p.vz) * Math.min(1, accel * dt);

    var r = 0.34;
    var nx = p.x + p.vx * dt;
    if (!blocked(nx, p.z, r)) p.x = nx; else p.vx *= -0.1;
    var nz = p.z + p.vz * dt;
    if (!blocked(p.x, nz, r)) p.z = nz; else p.vz *= -0.1;

    // прыжок и гравитация
    var groundY = BASE + 1;
    if (input.jump && p.onGround) {
      p.vy = 6.0; p.onGround = false; global.SFX.jump();
    }
    input.jump = false;
    if (!p.onGround) {
      p.vy -= 19.5 * dt;
      p.y += p.vy * dt;
      if (p.y <= groundY) { p.y = groundY; p.vy = 0; p.onGround = true; global.SFX.land(); }
    } else p.y = groundY;

    p.speed = Math.sqrt(p.vx * p.vx + p.vz * p.vz);
    if (p.speed > 0.25) {
      var want = Math.atan2(p.vx, p.vz);
      var d = ((want - p.yaw + Math.PI * 3) % (Math.PI * 2)) - Math.PI;
      p.yaw += d * Math.min(1, 12 * dt);
    }

    // выносливость
    if (run && p.speed > 1.5) p.stamina = Math.max(0, p.stamina - dt * 0.155);
    else p.stamina = Math.min(1, p.stamina + dt * (p.speed < 0.5 ? 0.32 : 0.17));

    // шаги
    p.stepTimer -= dt * p.speed * (run ? 1.35 : 1.0);
    if (p.stepTimer <= 0 && p.speed > 0.6 && p.onGround) {
      p.stepTimer = 0.85; global.SFX.step(run);
    }

    p.char.root.position.set(p.x, p.y, p.z);
    p.char.root.rotation.y = p.yaw;
    global.CHAR.update(p.char, dt, {
      speed: p.speed, airborne: !p.onGround,
      scared: S.alertLevel > 0.5,
      headYaw: Math.max(-0.6, Math.min(0.6, (cam.yaw - p.yaw + Math.PI * 3) % (Math.PI * 2) - Math.PI)) * 0.5
    });
    p.cell = cellOf(p.x, p.z);
  }

  // ------------------------------------------------------------------
  //  Менты
  // ------------------------------------------------------------------
  function updateCops(dt) {
    var L = S.level, D = DIFF[L.diff], p = S.player, BASE = global.WORLD.BASE;
    var anySeen = false;
    for (var i = 0; i < S.cops.length; i++) {
      var c = S.cops[i];
      c.cell = cellOf(c.x, c.z);
      var dxp = p.x - c.x, dzp = p.z - c.z;
      var distP = Math.sqrt(dxp * dxp + dzp * dzp);
      var cellDist = Math.abs(c.cell[0] - p.cell[0]) + Math.abs(c.cell[1] - p.cell[1]);

      // зрение
      var sees = false;
      if (S.startGrace <= 0 && cellDist <= D.vision && lineOfSight(c.cell, p.cell)) sees = true;
      if (distP < 3.2) sees = true;
      if (sees) {
        c.alert = 1; c.lastKnown = p.cell.slice(); anySeen = true;
        if (c.whistle <= 0) { global.SFX.whistle(); c.whistle = 4.5; }
      } else {
        c.alert = Math.max(0, c.alert - dt * 0.28);
      }
      c.whistle -= dt;

      // пересчёт маршрута: как в оригинале — менты ищут игрока поиском в ширину
      c.repathTimer -= dt;
      if (c.repathTimer <= 0) {
        var goal = null;
        if (S.startGrace > 0) {
          // фора в начале: менты просто патрулируют
          c.repathTimer = 1.2;
          c.state = 'patrol';
          var W = L.world.cells.w, H = L.world.cells.h, tries = 0;
          do {
            goal = [Math.floor(Math.random() * W), Math.floor(Math.random() * H)];
          } while (isWallCell(goal[0], goal[1]) && tries++ < 40);
        } else if (sees) {
          c.repathTimer = 0.40;
          c.state = 'chase';
          goal = p.cell;
        } else {
          // «чуйка»: идут по лабиринту в сторону игрока, но обновляются реже
          c.repathTimer = D.repath + Math.random() * 0.4;
          c.state = 'hunt';
          goal = c.lastKnown && c.alert > 0.05 ? c.lastKnown : p.cell;
        }
        var path = bfsPath(c.cell, goal);
        if (path && path.length) { c.path = path; c.pathIdx = 0; }
      }

      // движение по маршруту
      var speed = D.copSpeed * (c.alert > 0.5 ? 1.13 : 0.82);
      if (c.path && c.pathIdx < c.path.length) {
        var node = c.path[c.pathIdx];
        var w = L.cellToWorld(node[0], node[1]);
        var dx = w.x - c.x, dz = w.z - c.z;
        var d = Math.sqrt(dx * dx + dz * dz);
        if (d < 0.35) c.pathIdx++;
        else {
          var vx = dx / d * speed, vz = dz / d * speed;
          var r = 0.34;
          var nx = c.x + vx * dt, nz = c.z + vz * dt;
          if (!blocked(nx, c.z, r)) c.x = nx;
          if (!blocked(c.x, nz, r)) c.z = nz;
          var want = Math.atan2(vx, vz);
          var da = ((want - c.yaw + Math.PI * 3) % (Math.PI * 2)) - Math.PI;
          c.yaw += da * Math.min(1, 9 * dt);
          c.speed = speed;
        }
      } else c.speed = Math.max(0, c.speed - dt * 4);

      // далёких ментов не рисуем вовсе, средним отключаем мелкие детали
      var vis = distP < 30;
      if (c.char.root.visible !== vis) c.char.root.visible = vis;
      if (vis) {
        global.CHAR.setDetail(c.char, distP < 11);
        c.char.root.position.set(c.x, c.y, c.z);
        c.char.root.rotation.y = c.yaw;
        global.CHAR.update(c.char, dt, { speed: c.speed, angry: c.alert > 0.5 });
      }

      // поимка
      if (distP < 0.85 && S.startGrace <= 0 && S.screen === 'game') {
        endGame(false);
      }
    }
    S.seenAny = anySeen;
    var targetAlert = anySeen ? 1 : 0;
    S.alertLevel += (targetAlert - S.alertLevel) * Math.min(1, dt * 3);
    global.SFX.siren(anySeen);
  }

  // ------------------------------------------------------------------
  //  Камера
  // ------------------------------------------------------------------
  var _desired = new THREE.Vector3(), _look = new THREE.Vector3();
  function updateCamera(dt) {
    var p = S.player, eng = S.eng;
    var cy = Math.cos(cam.yaw), sy = Math.sin(cam.yaw);
    var pitch = cam.pitch;

    // подбираем дистанцию так, чтобы камера не влезала в стену
    var maxD = cam.dist;
    for (var t = 0.7; t <= cam.dist; t += 0.22) {
      var qx = p.x - sy * Math.cos(pitch) * t, qz = p.z - cy * Math.cos(pitch) * t;
      if (blocked(qx, qz, 0.32)) { maxD = Math.max(cam.minDist, t - 0.42); break; }
    }
    cam.curDist += (maxD - cam.curDist) * Math.min(1, dt * (maxD < cam.curDist ? 18 : 3.5));
    var squeeze = 1 - Math.min(1, (cam.curDist - cam.minDist) / Math.max(0.001, cam.dist - cam.minDist));

    // высота: не выше верха стен, иначе камера окажется внутри блока
    var height = Math.min(2.35, 1.50 + Math.sin(pitch) * 2.2 + squeeze * 0.12);
    var back = Math.cos(pitch) * cam.curDist;
    var px = p.x - sy * back, pz = p.z - cy * back;

    // если и на минимальной дистанции упираемся в стену — уходим от первого лица
    var fpNow = blocked(px, pz, 0.26);
    var fp = S.fpMode ? (fpNow || cam.curDist < 1.8) : fpNow;   // гистерезис, чтобы не мигало
    if (fp) {
      px = p.x + sy * 0.12; pz = p.z + cy * 0.12;
      height = 1.60;
    }
    if (S.fpMode !== fp) {
      S.fpMode = fp;
      p.char.root.visible = !fp;
    }
    _desired.set(px, p.y + height, pz);
    eng.camera.position.lerp(_desired, Math.min(1, dt * 15));
    if (cam.shake > 0) {
      eng.camera.position.x += (Math.random() - 0.5) * cam.shake * 0.1;
      eng.camera.position.y += (Math.random() - 0.5) * cam.shake * 0.1;
      cam.shake = Math.max(0, cam.shake - dt * 2);
    }
    if (fp) {
      _look.set(p.x + sy * 6, p.y + 1.60 - Math.sin(pitch) * 4.5, p.z + cy * 6);
    } else {
      _look.set(p.x, p.y + 1.28 - squeeze * 0.18, p.z);
    }
    eng.camera.lookAt(_look);
    eng.shadowTarget.set(p.x, p.y, p.z);
  }

  function updateMenuCamera(dt) {
    var eng = S.eng, L = S.level;
    if (!L) return;
    if (S.charPreview && S.player) {
      // близкий облёт вокруг персонажа для панели выбора
      S.previewAngle += dt * 0.42;
      var e0 = L.exit, by = global.WORLD.BASE + 1;
      var bx = e0.x, bz = e0.z + 3.2;
      S.player.char.root.position.set(bx, by, bz);
      S.player.char.root.rotation.y = S.previewAngle;
      global.CHAR.setDetail(S.player.char, true);
      global.CHAR.update(S.player.char, dt, { speed: 0 });
      eng.camera.position.set(bx, by + 1.28, bz + 2.30);
      eng.camera.lookAt(bx, by + 0.98, bz);
      eng.shadowTarget.set(bx, by, bz);
      eng.shadowRadius = 10;
      return;
    }
    S.menuAngle += dt * 0.055;
    var w = L.world;
    var cx = w.sx / 2, cz = w.sz / 2;
    var rad = Math.max(w.sx, w.sz) * 0.62;
    var y = global.WORLD.BASE + 16 + Math.sin(S.menuAngle * 0.7) * 5;
    eng.camera.position.set(cx + Math.cos(S.menuAngle) * rad, y, cz + Math.sin(S.menuAngle) * rad);
    eng.camera.lookAt(cx, global.WORLD.BASE + 1, cz);
    eng.shadowTarget.set(cx, global.WORLD.BASE, cz);
    eng.shadowRadius = 60;
    if (S.player) {
      // персонаж стоит у портала и оглядывается
      var e = L.exit;
      S.player.char.root.position.set(e.x, global.WORLD.BASE + 1, e.z + 2.2);
      S.player.char.root.rotation.y = Math.sin(S.menuAngle * 1.3) * 0.6 + Math.PI;
      global.CHAR.update(S.player.char, dt, { speed: 0 });
    }
  }

  // ------------------------------------------------------------------
  //  Монеты, фонари, мини-карта, HUD
  // ------------------------------------------------------------------
  var _m4 = new THREE.Matrix4(), _q = new THREE.Quaternion(), _v3 = new THREE.Vector3(), _sc = new THREE.Vector3(1, 1, 1);
  function updateCoins(dt) {
    var p = S.player, mesh = S.coinMesh;
    if (!mesh) return;
    var idx = 0;
    for (var i = 0; i < S.coins.length; i++) {
      var c = S.coins[i];
      if (c.taken) continue;
      c.spin += dt * 2.4;
      var dx = c.x - p.x, dz = c.z - p.z, dy = c.y - (p.y + 1);
      if (dx * dx + dz * dz + dy * dy < 1.1 && S.screen === 'game') {
        c.taken = true; S.coinsGot++; global.SFX.coin();
        toast('+1 монета');
        continue;
      }
      _q.setFromEuler(new THREE.Euler(0, c.spin, 0.35));
      _v3.set(c.x, c.y + Math.sin(c.spin * 1.5) * 0.09, c.z);
      _m4.compose(_v3, _q, _sc);
      mesh.setMatrixAt(idx++, _m4);
    }
    mesh.count = idx;
    mesh.instanceMatrix.needsUpdate = true;
  }

  function updateLights() {
    var eng = S.eng, L = S.level;
    if (!L) return;
    var pos = eng.common.uPointPos.value, col = eng.common.uPointCol.value;
    var n = 0;
    var px = S.player ? S.player.x : eng.camera.position.x;
    var pz = S.player ? S.player.z : eng.camera.position.z;
    var night = eng.isNight ? 1 : 0.25;
    // портал светится всегда
    pos[n].set(L.exit.x, global.WORLD.BASE + 2.2, L.exit.z, 8.5);
    col[n].set(0.35, 1.5, 0.95, 1.2); n++;
    if (night > 0.5) {
      var list = L.lanterns.slice().sort(function (a, b) {
        return ((a.lx - px) * (a.lx - px) + (a.lz - pz) * (a.lz - pz)) - ((b.lx - px) * (b.lx - px) + (b.lz - pz) * (b.lz - pz));
      });
      for (var i = 0; i < list.length && n < global.GFX.MAX_POINTS; i++) {
        var l = list[i];
        var d2 = (l.lx - px) * (l.lx - px) + (l.lz - pz) * (l.lz - pz);
        if (d2 > 420) break;
        pos[n].set(l.lx, l.ly, l.lz, 8.5);
        col[n].set(1.0, 0.70, 0.34, 1.35); n++;
      }
    }
    eng.common.uPointCount.value = n;
  }

  var mmCtx = null;
  function drawMinimap() {
    var L = S.level, p = S.player;
    if (!L || !p) return;
    var cv = $('minimap');
    if (!mmCtx) mmCtx = cv.getContext('2d');
    var g = mmCtx, W = cv.width, H = cv.height;
    var R = 6;                     // радиус обзора в клетках
    var cs = W / (R * 2 + 1);
    g.clearRect(0, 0, W, H);
    var pc = p.cell;
    // отметить исследованное
    for (var dr = -3; dr <= 3; dr++) for (var dc = -3; dc <= 3; dc++) {
      var rr = pc[1] + dr, cc = pc[0] + dc;
      if (rr < 0 || cc < 0 || rr >= L.world.cells.h || cc >= L.world.cells.w) continue;
      if (dr * dr + dc * dc <= 10) L.explored[rr * L.world.cells.w + cc] = 1;
    }
    for (var r = -R; r <= R; r++) {
      for (var c = -R; c <= R; c++) {
        var mr = pc[1] + r, mc = pc[0] + c;
        if (mr < 0 || mc < 0 || mr >= L.world.cells.h || mc >= L.world.cells.w) continue;
        if (!L.explored[mr * L.world.cells.w + mc]) continue;
        var x = (c + R) * cs, y = (r + R) * cs;
        var d = Math.sqrt(r * r + c * c) / R;
        g.globalAlpha = Math.max(0.15, 1 - d * 0.85);
        g.fillStyle = L.world.wallGrid[mr][mc] ? '#1b2436' : '#7f8ea8';
        g.fillRect(x, y, cs + 0.6, cs + 0.6);
      }
    }
    g.globalAlpha = 1;
    function plot(cellX, cellY, color, size) {
      var r2 = cellY - pc[1], c2 = cellX - pc[0];
      if (Math.abs(r2) > R || Math.abs(c2) > R) {
        // стрелка к цели у края
        var a = Math.atan2(r2, c2);
        var px2 = W / 2 + Math.cos(a) * (W / 2 - 8), py2 = H / 2 + Math.sin(a) * (H / 2 - 8);
        g.fillStyle = color; g.beginPath(); g.arc(px2, py2, size * 0.8, 0, 6.3); g.fill();
        return;
      }
      var x = (c2 + R) * cs + cs / 2, y = (r2 + R) * cs + cs / 2;
      g.fillStyle = color; g.beginPath(); g.arc(x, y, size, 0, 6.3); g.fill();
    }
    // монеты
    for (var i = 0; i < S.coins.length; i++) {
      if (S.coins[i].taken) continue;
      var cc2 = cellOf(S.coins[i].x, S.coins[i].z);
      if (!L.explored[cc2[1] * L.world.cells.w + cc2[0]]) continue;
      plot(cc2[0], cc2[1], '#ffc94d', cs * 0.22);
    }
    // выход
    plot(L.exit.cell[0], L.exit.cell[1], '#57e08a', cs * 0.36);
    // менты
    for (var j = 0; j < S.cops.length; j++) {
      var cp = S.cops[j];
      var dd = Math.abs(cp.cell[0] - pc[0]) + Math.abs(cp.cell[1] - pc[1]);
      if (dd > R + 2 && cp.alert < 0.5) continue;
      plot(cp.cell[0], cp.cell[1], cp.alert > 0.5 ? '#ff4d4d' : '#ff9d6b', cs * 0.30);
    }
    // игрок
    g.fillStyle = '#ffffff';
    g.beginPath(); g.arc(W / 2, H / 2, cs * 0.32, 0, 6.3); g.fill();
    g.strokeStyle = 'rgba(255,255,255,.65)'; g.lineWidth = 2;
    g.beginPath(); g.moveTo(W / 2, H / 2);
    g.lineTo(W / 2 + Math.sin(S.player.yaw) * cs * 0.9, H / 2 + Math.cos(S.player.yaw) * cs * 0.9);
    g.stroke();
  }

  function updateHUD(dt) {
    $('hudTime').textContent = fmtTime(S.time);
    $('hudCoins').textContent = S.coinsGot + '/' + S.coins.length;
    $('stamina').firstElementChild.style.width = Math.round(S.player.stamina * 100) + '%';
    $('alert').style.opacity = S.alertLevel * 0.85;
    $('alertTxt').style.opacity = S.alertLevel > 0.4 ? 1 : 0;
    if (toastTimer > 0) {
      toastTimer -= dt;
      if (toastTimer <= 0) { $('toast').style.opacity = 0; $('toast').style.transform = 'translateY(10px)'; }
    }
    drawMinimap();
  }

  // ------------------------------------------------------------------
  //  Главный цикл
  // ------------------------------------------------------------------
  var last = 0;
  function frame(now) {
    requestAnimationFrame(frame);
    var dt = Math.min(0.05, (now - last) / 1000 || 0.016);
    last = now;
    var eng = S.eng;
    eng.common.uTime.value += dt;

    if (S.screen === 'game' && !S.paused) {
      S.time += dt;
      if (S.startGrace > 0) S.startGrace -= dt;
      updatePlayer(dt);
      updateCops(dt);
      updateCoins(dt);
      updateCamera(dt);
      updateHUD(dt);
      // победа
      var dxe = S.player.x - S.level.exit.x, dze = S.player.z - S.level.exit.z;
      if (dxe * dxe + dze * dze < 1.6 && !S.noWin) endGame(true);
    } else if (S.screen === 'menu') {
      updateMenuCamera(dt);
    } else if (S.screen === 'end' || S.paused) {
      if (S.player) {
        updateCamera(dt);
        global.CHAR.update(S.player.char, dt, { speed: 0, scared: !S.won });
      }
    }
    updateLights();

    // адаптивное разрешение: держим плавность на слабых телефонах
    S.fpsAcc += dt; S.fpsCount++;
    if (S.fpsAcc >= 0.5) {
      S.fps = S.fpsCount / S.fpsAcc;
      S.fpsAcc = 0; S.fpsCount = 0;
      if (CFG.fps) $('fps').textContent = Math.round(S.fps) + ' fps · ' + Math.round(eng.renderScale * 100) + '%';
      S.adaptTimer += 0.5;
      if (S.adaptTimer > 2) {
        if (S.fps < 34 && eng.renderScale > 0.6) { eng.renderScale = Math.max(0.6, eng.renderScale - 0.08); resize(); S.adaptTimer = 0; }
        else if (S.fps > 56 && eng.renderScale < eng.Q.render) { eng.renderScale = Math.min(eng.Q.render, eng.renderScale + 0.05); resize(); S.adaptTimer = 0; }
      }
    }
    eng.render(dt);
  }

  window.addEventListener('load', boot);
  // Прогон логики без отрисовки — для отладки ИИ и баланса
  function simulate(seconds, dt) {
    dt = dt || 1 / 30;
    var steps = Math.floor(seconds / dt), i = 0;
    for (; i < steps && S.screen === 'game'; i++) {
      S.time += dt;
      if (S.startGrace > 0) S.startGrace -= dt;
      updatePlayer(dt);
      updateCops(dt);
      updateCoins(dt);
      var dxe = S.player.x - S.level.exit.x, dze = S.player.z - S.level.exit.z;
      if (dxe * dxe + dze * dze < 1.6 && !S.noWin) endGame(true);
    }
    return { steps: i, screen: S.screen, time: S.time };
  }
  S._dev = { buildLevel: buildLevel, startGame: startGame, endGame: endGame, DIFF: DIFF, CFG: CFG,
             cam: cam, input: input, toMenu: toMenu, pause: pause, simulate: simulate,
             bfsPath: bfsPath, cellOf: cellOf, isWallCell: isWallCell };
  global.GAME = S;
})(window);
