/* =====================================================================
   world.js — генерация мира: остров, лабиринт из карты игры,
   воксельная геометрия с запечённым AO, растительность, декор.
   ===================================================================== */
(function (global) {
  'use strict';
  var N = global.NZ;

  var CELL = 2;          // одна клетка лабиринта = 2 блока
  var BASE = 6;          // высота плато с лабиринтом
  var WATER = 2.35;      // уровень воды
  var MARGIN = 14;       // блоков острова вокруг лабиринта

  // Типы блоков: [top, side, bottom]
  var B = {};
  function defBlock(name, top, side, bottom, opts) {
    B[name] = Object.assign({ id: Object.keys(B).length + 1, top: top, side: side, bottom: bottom, solid: true }, opts || {});
    return B[name];
  }

  function init(TILES) {
    B = {};
    defBlock('GRASS', TILES.GRASS_TOP, TILES.GRASS_SIDE, TILES.DIRT);
    defBlock('DIRT', TILES.DIRT, TILES.DIRT, TILES.DIRT);
    defBlock('SAND', TILES.SAND, TILES.SAND, TILES.SAND);
    defBlock('ROCK', TILES.ROCK, TILES.ROCK, TILES.ROCK);
    defBlock('COBBLE', TILES.COBBLE, TILES.COBBLE, TILES.COBBLE);
    defBlock('GRAVEL', TILES.GRAVEL, TILES.GRAVEL, TILES.GRAVEL);
    defBlock('FLAG', TILES.FLAGSTONE, TILES.FLAGSTONE, TILES.FLAGSTONE);
    defBlock('LOG', TILES.LOG_TOP, TILES.BARK, TILES.LOG_TOP);
    defBlock('LEAVES', TILES.LEAVES, TILES.LEAVES, TILES.LEAVES, { foliage: true });
    defBlock('HEDGE', TILES.HEDGE, TILES.HEDGE, TILES.HEDGE);
    defBlock('PLANKS', TILES.PLANKS, TILES.PLANKS, TILES.PLANKS);
    defBlock('BRICK', TILES.BRICK, TILES.BRICK, TILES.BRICK);
    defBlock('ROOF', TILES.ROOF, TILES.ROOF, TILES.ROOF);
    defBlock('MUD', TILES.MUD, TILES.MUD, TILES.MUD);
    return B;
  }

  // ---------------------------------------------------------------
  //  Воксельный контейнер
  // ---------------------------------------------------------------
  function Volume(sx, sy, sz) {
    this.sx = sx; this.sy = sy; this.sz = sz;
    this.data = new Uint8Array(sx * sy * sz);
  }
  Volume.prototype.idx = function (x, y, z) { return (y * this.sz + z) * this.sx + x; };
  Volume.prototype.get = function (x, y, z) {
    if (x < 0 || y < 0 || z < 0 || x >= this.sx || y >= this.sy || z >= this.sz) return y < 0 ? 255 : 0;
    return this.data[(y * this.sz + z) * this.sx + x];
  };
  Volume.prototype.set = function (x, y, z, v) {
    if (x < 0 || y < 0 || z < 0 || x >= this.sx || y >= this.sy || z >= this.sz) return;
    this.data[(y * this.sz + z) * this.sx + x] = v;
  };

  // ---------------------------------------------------------------
  //  Меширование: 6 граней, запечённое вершинное AO
  // ---------------------------------------------------------------
  var FACES = [
    { dir: [1, 0, 0], corners: [[1, 1, 1], [1, 0, 1], [1, 0, 0], [1, 1, 0]], uvAxis: [2, 1] },
    { dir: [-1, 0, 0], corners: [[0, 1, 0], [0, 0, 0], [0, 0, 1], [0, 1, 1]], uvAxis: [2, 1] },
    { dir: [0, 1, 0], corners: [[0, 1, 1], [1, 1, 1], [1, 1, 0], [0, 1, 0]], uvAxis: [0, 2] },
    { dir: [0, -1, 0], corners: [[0, 0, 0], [1, 0, 0], [1, 0, 1], [0, 0, 1]], uvAxis: [0, 2] },
    { dir: [0, 0, 1], corners: [[0, 1, 1], [0, 0, 1], [1, 0, 1], [1, 1, 1]], uvAxis: [0, 1] },
    { dir: [0, 0, -1], corners: [[1, 1, 0], [1, 0, 0], [0, 0, 0], [0, 1, 0]], uvAxis: [0, 1] }
  ];

  // соседи для AO каждой вершины грани: [side1, side2, corner] в локальных смещениях
  function aoNeighbours(face, ci) {
    var d = FACES[face].dir;
    var c = FACES[face].corners[ci];
    // локальные оси грани
    var ax, ay;
    if (d[0] !== 0) { ax = [0, 0, 1]; ay = [0, 1, 0]; }
    else if (d[1] !== 0) { ax = [1, 0, 0]; ay = [0, 0, 1]; }
    else { ax = [1, 0, 0]; ay = [0, 1, 0]; }
    // знак смещения по осям — из позиции угла
    var sx = (c[0] * ax[0] + c[1] * ax[1] + c[2] * ax[2]) > 0 ? 1 : -1;
    var sy = (c[0] * ay[0] + c[1] * ay[1] + c[2] * ay[2]) > 0 ? 1 : -1;
    var s1 = [d[0] + ax[0] * sx, d[1] + ax[1] * sx, d[2] + ax[2] * sx];
    var s2 = [d[0] + ay[0] * sy, d[1] + ay[1] * sy, d[2] + ay[2] * sy];
    var cr = [d[0] + ax[0] * sx + ay[0] * sy, d[1] + ax[1] * sx + ay[1] * sy, d[2] + ax[2] * sx + ay[2] * sy];
    return [s1, s2, cr];
  }
  var AO_CACHE = [];
  for (var f = 0; f < 6; f++) { AO_CACHE[f] = []; for (var ci = 0; ci < 4; ci++) AO_CACHE[f][ci] = aoNeighbours(f, ci); }

  function Mesher() {
    this.pos = []; this.nrm = []; this.uv = []; this.tile = []; this.ao = []; this.wind = []; this.idx = [];
    this.count = 0;
  }
  Mesher.prototype.quad = function (x, y, z, face, tile, aoVals, windBase) {
    var F = FACES[face], c = F.corners, d = F.dir;
    var base = this.count;
    var uvs = [[0, 1], [0, 0], [1, 0], [1, 1]];
    if (face === 2 || face === 3) uvs = [[0, 0], [1, 0], [1, 1], [0, 1]];
    for (var i = 0; i < 4; i++) {
      this.pos.push(x + c[i][0], y + c[i][1], z + c[i][2]);
      this.nrm.push(d[0], d[1], d[2]);
      this.uv.push(uvs[i][0], uvs[i][1]);
      this.tile.push(tile);
      this.ao.push(aoVals[i]);
      this.wind.push((windBase || 0) * (0.30 + 0.70 * c[i][1]));
    }
    // разворот триангуляции, чтобы AO не «ломалось» диагональю
    if (aoVals[0] + aoVals[2] > aoVals[1] + aoVals[3]) {
      this.idx.push(base, base + 1, base + 2, base, base + 2, base + 3);
    } else {
      this.idx.push(base + 1, base + 2, base + 3, base + 1, base + 3, base);
    }
    this.count += 4;
  };
  Mesher.prototype.geometry = function (THREE) {
    var g = new THREE.BufferGeometry();
    g.setAttribute('position', new THREE.Float32BufferAttribute(this.pos, 3));
    g.setAttribute('normal', new THREE.Float32BufferAttribute(this.nrm, 3));
    g.setAttribute('uv', new THREE.Float32BufferAttribute(this.uv, 2));
    g.setAttribute('aTile', new THREE.Float32BufferAttribute(this.tile, 1));
    g.setAttribute('aAO', new THREE.Float32BufferAttribute(this.ao, 1));
    g.setAttribute('aWind', new THREE.Float32BufferAttribute(this.wind, 1));
    g.setIndex(this.idx);
    g.computeBoundingSphere();
    return g;
  };

  // произвольный квад (кресты травы, флаги и т.п.)
  Mesher.prototype.rawQuad = function (p0, p1, p2, p3, nrm, tile, ao, windTop, windBottom) {
    var base = this.count;
    var pts = [p0, p1, p2, p3];
    var uvs = [[0, 0], [1, 0], [1, 1], [0, 1]];
    var wb = windBottom || 0;
    var wind = [wb, wb, windTop, windTop];
    for (var i = 0; i < 4; i++) {
      this.pos.push(pts[i][0], pts[i][1], pts[i][2]);
      this.nrm.push(nrm[0], nrm[1], nrm[2]);
      this.uv.push(uvs[i][0], uvs[i][1]);
      this.tile.push(tile);
      this.ao.push(ao);
      this.wind.push(wind[i]);
    }
    this.idx.push(base, base + 1, base + 2, base, base + 2, base + 3);
    this.count += 4;
  };

  // Кусты высокой травы крестами на травяных клетках
  function addGrassTufts(M, world, TILES, density) {
    var rnd = N.mulberry32(1337);
    var sx = world.sx, sz = world.sz, vol = world.vol;
    var grassId = B.GRASS.id;
    for (var z = 1; z < sz - 1; z++) {
      for (var x = 1; x < sx - 1; x++) {
        var h = world.height[z * sx + x];
        var top = vol.get(x, h, z);
        // на плато лабиринта трава тоже растёт (пол коридоров)
        var y = h;
        if (x >= world.mazeX0 && x < world.mazeX0 + world.cells.w * CELL &&
            z >= world.mazeZ0 && z < world.mazeZ0 + world.cells.h * CELL) {
          y = BASE; top = vol.get(x, BASE, z);
          if (vol.get(x, BASE + 1, z) !== 0) continue;
        }
        if (top !== grassId) continue;
        var n = N.fbm(x * 0.11, z * 0.11, 4096, 77, 3);
        var chance = density * (0.35 + n * 1.5);
        if (rnd() > chance) continue;
        var ox = x + 0.5 + (rnd() - 0.5) * 0.7, oz = z + 0.5 + (rnd() - 0.5) * 0.7;
        var oy = y + 1;
        var s = 0.55 + rnd() * 0.65, hgt = 0.7 + rnd() * 0.75;
        var a = rnd() * Math.PI;
        var tile = TILES.TALL_GRASS;
        for (var k = 0; k < 2; k++) {
          var ang = a + k * Math.PI / 2;
          var cx = Math.cos(ang) * s * 0.5, cz = Math.sin(ang) * s * 0.5;
          M.rawQuad(
            [ox - cx, oy, oz - cz], [ox + cx, oy, oz + cz],
            [ox + cx, oy + hgt, oz + cz], [ox - cx, oy + hgt, oz - cz],
            [Math.sin(ang), 0, -Math.cos(ang)], tile, 1.0, 1.0);
        }
      }
    }
  }

  // Плющ и свисающая зелень на стенах лабиринта
  function addWallVines(M, world, TILES, density) {
    var rnd = N.mulberry32(90210);
    var W = world.cells.w, H = world.cells.h;
    var top = BASE + (world.wallH || 3) + 1;
    var dirs = [[1, 0], [-1, 0], [0, 1], [0, -1]];
    for (var r = 0; r < H; r++) {
      for (var c = 0; c < W; c++) {
        if (!world.wallGrid[r][c]) continue;
        for (var d = 0; d < 4; d++) {
          var nc = c + dirs[d][0], nr = r + dirs[d][1];
          if (nc < 0 || nr < 0 || nc >= W || nr >= H) continue;
          if (world.wallGrid[nr][nc]) continue;         // соседняя клетка тоже стена
          if (rnd() > density) continue;
          var bx = world.mazeX0 + c * CELL, bz = world.mazeZ0 + r * CELL;
          var n = 1 + (rnd() < 0.5 ? 1 : 0);
          for (var k = 0; k < n; k++) {
            var w = 0.55 + rnd() * 0.75;
            var h = 0.9 + rnd() * 1.5;
            var off = rnd() * (CELL - w);
            var eps = 0.045;
            var x0, z0, x1, z1, nx, nz;
            if (dirs[d][0] === 1) { x0 = x1 = bx + CELL + eps; z0 = bz + off; z1 = z0 + w; nx = 1; nz = 0; }
            else if (dirs[d][0] === -1) { x0 = x1 = bx - eps; z0 = bz + off + w; z1 = bz + off; nx = -1; nz = 0; }
            else if (dirs[d][1] === 1) { z0 = z1 = bz + CELL + eps; x0 = bx + off + w; x1 = bx + off; nx = 0; nz = 1; }
            else { z0 = z1 = bz - eps; x0 = bx + off; x1 = x0 + w; nx = 0; nz = -1; }
            var yTop = top - 0.05 - rnd() * 0.35;
            var yBot = yTop - h;
            M.rawQuad(
              [x0, yBot, z0], [x1, yBot, z1], [x1, yTop, z1], [x0, yTop, z0],
              [nx, 0, nz], TILES.VINE, 0.92, 0.0, 0.9);
          }
        }
      }
    }
  }

  function buildMesh(THREE, vol, blockDefs, wantFoliage) {
    var solidM = new Mesher(), folM = new Mesher();
    var sx = vol.sx, sy = vol.sy, sz = vol.sz;
    var defsById = {};
    for (var k in blockDefs) defsById[blockDefs[k].id] = blockDefs[k];

    function occl(x, y, z) {
      var v = vol.get(x, y, z);
      if (v === 0) return 0;
      if (v === 255) return 1;
      var d = defsById[v];
      return (d && d.foliage) ? 0 : 1;
    }

    for (var y = 0; y < sy; y++) {
      for (var z = 0; z < sz; z++) {
        for (var x = 0; x < sx; x++) {
          var v = vol.get(x, y, z);
          if (v === 0) continue;
          var def = defsById[v];
          if (!def) continue;
          var M = def.foliage ? folM : solidM;
          for (var fi = 0; fi < 6; fi++) {
            var d = FACES[fi].dir;
            var nx = x + d[0], ny = y + d[1], nz = z + d[2];
            var nv = vol.get(nx, ny, nz);
            if (nv !== 0) {
              var nd = defsById[nv];
              var neighborSolid = (nv === 255) || (nd && !nd.foliage);
              if (neighborSolid) continue;
              if (nd && def.foliage && nd.foliage) continue; // листва к листве — не рисуем
            }
            var tile = fi === 2 ? def.top : (fi === 3 ? def.bottom : def.side);
            var aoVals = [1, 1, 1, 1];
            if (!def.foliage) {
              for (var ci = 0; ci < 4; ci++) {
                var nb = AO_CACHE[fi][ci];
                var s1 = occl(x + nb[0][0], y + nb[0][1], z + nb[0][2]);
                var s2 = occl(x + nb[1][0], y + nb[1][1], z + nb[1][2]);
                var cr = occl(x + nb[2][0], y + nb[2][1], z + nb[2][2]);
                var a = (s1 && s2) ? 0 : (3 - (s1 + s2 + cr));
                aoVals[ci] = 0.42 + 0.58 * (a / 3);
              }
            }
            M.quad(x, y, z, fi, tile, aoVals, def.foliage ? 1.0 : 0.0);
          }
        }
      }
    }
    return { solid: solidM, foliage: folM };
  }

  // ---------------------------------------------------------------
  //  Генерация уровня из карты оригинальной игры
  // ---------------------------------------------------------------
  function generate(THREE, mapGrid, seed, opts) {
    opts = opts || {};
    var MH = mapGrid.length, MW = mapGrid[0].length;
    var wallH = opts.wallHeight || 3;
    var sx = MW * CELL + MARGIN * 2;
    var sz = MH * CELL + MARGIN * 2;
    var sy = BASE + wallH + 8;
    var vol = new Volume(sx, sy, sz);
    var rnd = N.mulberry32(seed);

    var mazeX0 = MARGIN, mazeZ0 = MARGIN;
    var mazeX1 = MARGIN + MW * CELL, mazeZ1 = MARGIN + MH * CELL;

    // --- рельеф острова -------------------------------------------
    var height = new Float32Array(sx * sz);
    var surfBlock = new Uint8Array(sx * sz);
    for (var z = 0; z < sz; z++) {
      for (var x = 0; x < sx; x++) {
        // расстояние от прямоугольника лабиринта
        var dx = Math.max(mazeX0 - x, 0, x - (mazeX1 - 1));
        var dz = Math.max(mazeZ0 - z, 0, z - (mazeZ1 - 1));
        var d = Math.sqrt(dx * dx + dz * dz);
        var h = BASE;
        if (d > 3) {
          var t = N.clamp((d - 3) / (MARGIN - 5), 0, 1);
          var hills = (N.fbm(x * 0.045, z * 0.045, 4096, seed + 5, 4) - 0.5) * 5.0;
          var slope = Math.pow(t, 1.35);
          h = BASE - slope * (BASE + 2.5) + hills * (1 - Math.abs(t - 0.35)) * 1.3;
          h += (N.fbm(x * 0.13, z * 0.13, 4096, seed + 9, 3) - 0.5) * 1.6 * t;
        }
        var hi = Math.max(0, Math.round(h));
        height[z * sx + x] = hi;
        // тип поверхности
        var blk;
        if (hi <= WATER + 0.9) blk = B.SAND;
        else if (hi <= WATER + 1.8) blk = (N.vnoise(x * 0.3, z * 0.3, 4096, seed + 3) > 0.45 ? B.SAND : B.GRASS);
        else {
          var rocky = N.fbm(x * 0.08, z * 0.08, 4096, seed + 21, 4);
          blk = rocky > 0.63 ? (rocky > 0.70 ? B.ROCK : B.GRAVEL) : B.GRASS;
        }
        surfBlock[z * sx + x] = blk.id;
        // столб земли (только верхние слои — низ не виден)
        var depth = Math.max(0, hi - 4);
        for (var y = depth; y <= hi; y++) {
          var v = (y === hi) ? blk.id : (y > hi - 3 ? (blk === B.SAND ? B.SAND.id : B.DIRT.id) : B.ROCK.id);
          vol.set(x, y, z, v);
        }
        if (depth > 0) for (var y2 = 0; y2 < depth; y2++) vol.set(x, y2, z, 255); // невидимая заглушка
      }
    }

    // --- лабиринт --------------------------------------------------
    var wallGrid = [];            // [row][col] = true если стена
    var cells = { w: MW, h: MH };
    var playerCell = null, exitCell = null, copCells = [];
    for (var r = 0; r < MH; r++) {
      wallGrid[r] = [];
      for (var c = 0; c < MW; c++) {
        var s = mapGrid[r][c] || '';
        var isWall = s.indexOf('T') >= 0;
        wallGrid[r][c] = isWall;
        if (s.indexOf('C') >= 0) playerCell = [c, r];
        if (s.indexOf('A') >= 0) exitCell = [c, r];
        if (s.indexOf('P') >= 0) copCells.push([c, r]);
      }
    }

    // пол лабиринта + стены
    for (var r2 = 0; r2 < MH; r2++) {
      for (var c2 = 0; c2 < MW; c2++) {
        var bx = mazeX0 + c2 * CELL, bz = mazeZ0 + r2 * CELL;
        var wall = wallGrid[r2][c2];
        var kind = N.fbm(c2 * 0.22, r2 * 0.22, 4096, seed + 31, 3);
        for (var ox = 0; ox < CELL; ox++) for (var oz = 0; oz < CELL; oz++) {
          var wx = bx + ox, wz = bz + oz;
          if (wall) {
            // основание — камень/булыжник, верх — живая изгородь
            var stoneH = kind > 0.52 ? wallH : Math.max(1, wallH - 2);
            var stoneId = kind > 0.72 ? B.BRICK.id : (kind > 0.60 ? B.ROCK.id : B.COBBLE.id);
            for (var y3 = BASE + 1; y3 <= BASE + wallH; y3++) {
              var t;
              if (y3 <= BASE + stoneH) t = stoneId;
              else t = B.HEDGE.id;
              vol.set(wx, y3, wz, t);
            }
            vol.set(wx, BASE, wz, B.COBBLE.id);
          } else {
            // пол коридора
            var pn = N.fbm(wx * 0.22, wz * 0.22, 4096, seed + 41, 4);
            var floorB = pn > 0.58 ? B.GRAVEL : (pn < 0.36 ? B.GRASS : B.FLAG);
            vol.set(wx, BASE, wz, floorB.id);
          }
        }
      }
    }

    // --- растительность и декор ------------------------------------
    var decor = [];   // {type,x,y,z,rot,scale}
    var lanterns = [];
    // деревья на острове
    var treeTry = Math.floor(sx * sz * 0.02);
    for (var i = 0; i < treeTry; i++) {
      var tx = 2 + Math.floor(rnd() * (sx - 4)), tz = 2 + Math.floor(rnd() * (sz - 4));
      if (tx >= mazeX0 - 2 && tx < mazeX1 + 2 && tz >= mazeZ0 - 2 && tz < mazeZ1 + 2) continue;
      var th = height[tz * sx + tx];
      if (th <= WATER + 1.6) continue;
      if (surfBlock[tz * sx + tx] !== B.GRASS.id) continue;
      // не ставим деревья вплотную
      var ok = true;
      for (var dd = 0; dd < decor.length; dd++) {
        var o = decor[dd];
        if (o.type === 'tree' && Math.abs(o.x - tx) < 4 && Math.abs(o.z - tz) < 4) { ok = false; break; }
      }
      if (!ok) continue;
      decor.push({ type: 'tree', x: tx, y: th, z: tz });
      // ствол
      var trunkH = 4 + Math.floor(rnd() * 3);
      for (var ty = 1; ty <= trunkH; ty++) vol.set(tx, th + ty, tz, B.LOG.id);
      // крона
      var top = th + trunkH;
      var rad = 2 + (rnd() > 0.6 ? 1 : 0);
      for (var ly = -2; ly <= 2; ly++) {
        for (var lx = -rad; lx <= rad; lx++) for (var lz = -rad; lz <= rad; lz++) {
          var dl = Math.sqrt(lx * lx + lz * lz * 1.0 + ly * ly * 1.6);
          if (dl > rad + 0.6) continue;
          if (dl > rad - 0.2 && rnd() > 0.45) continue;
          if (lx === 0 && lz === 0 && ly < 1) continue;
          if (vol.get(tx + lx, top + ly, tz + lz) === 0) vol.set(tx + lx, top + ly, tz + lz, B.LEAVES.id);
        }
      }
    }

    // валуны
    for (var bi = 0; bi < sx * 0.4; bi++) {
      var rx = 2 + Math.floor(rnd() * (sx - 4)), rz = 2 + Math.floor(rnd() * (sz - 4));
      if (rx >= mazeX0 - 1 && rx < mazeX1 + 1 && rz >= mazeZ0 - 1 && rz < mazeZ1 + 1) continue;
      var rh = height[rz * sx + rx];
      if (rh <= WATER) continue;
      var rr = rnd() > 0.6 ? 2 : 1;
      for (var by = 0; by <= rr; by++) for (var bx2 = -rr; bx2 <= rr; bx2++) for (var bz2 = -rr; bz2 <= rr; bz2++) {
        if (bx2 * bx2 + bz2 * bz2 + by * by * 1.4 > rr * rr + 0.4) continue;
        if (vol.get(rx + bx2, rh + by, rz + bz2) === 0 || by === 0)
          vol.set(rx + bx2, rh + by + 1, rz + bz2, rnd() > 0.35 ? B.ROCK.id : B.COBBLE.id);
      }
    }

    return {
      vol: vol, height: height, surfBlock: surfBlock,
      sx: sx, sy: sy, sz: sz,
      wallGrid: wallGrid, cells: cells, wallH: wallH,
      mazeX0: mazeX0, mazeZ0: mazeZ0, CELL: CELL, BASE: BASE, WATER: WATER,
      playerCell: playerCell, exitCell: exitCell, copCells: copCells,
      decor: decor, lanterns: lanterns, rnd: rnd
    };
  }

  global.WORLD = {
    init: init, generate: generate, buildMesh: buildMesh, Volume: Volume,
    addGrassTufts: addGrassTufts, addWallVines: addWallVines,
    Mesher: Mesher, CELL: CELL, BASE: BASE, WATER: WATER, MARGIN: MARGIN,
    blocks: function () { return B; }
  };
})(window);
