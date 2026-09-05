/* =====================================================================
   props.js — объекты: портал выхода, монеты, фонари, указатели.
   ===================================================================== */
(function (global) {
  'use strict';

  // ---- светящийся портал выхода -----------------------------------
  function makeExit(THREE, common, depthMat) {
    var group = new THREE.Object3D();
    var MAT = global.MAT;
    var stone = MAT.makeObjectMaterial(THREE, common, { color: 0x8a8578, rough: 0.85, noiseAmt: 0.35, noiseScale: 8 });
    var gold = MAT.makeObjectMaterial(THREE, common, { color: 0xc9a227, rough: 0.28, metal: 0.85 });

    // две колонны
    function pillar(x) {
      var g = global.CHAR.loft(THREE, [
        { y: 0.00, rx: 0.30, rz: 0.30, sq: 0.6 },
        { y: 0.18, rx: 0.25, rz: 0.25, sq: 0.55 },
        { y: 0.55, rx: 0.21, rz: 0.21, sq: 0.5 },
        { y: 2.10, rx: 0.20, rz: 0.20, sq: 0.5 },
        { y: 2.35, rx: 0.27, rz: 0.27, sq: 0.6 },
        { y: 2.50, rx: 0.24, rz: 0.24, sq: 0.6 }
      ], 12, true, true);
      var m = new THREE.Mesh(g, stone);
      m.position.x = x;
      m.userData.depthMat = depthMat;
      group.add(m);
      return m;
    }
    pillar(-0.95); pillar(0.95);
    // перемычка
    var lintel = new THREE.Mesh(global.CHAR.loft(THREE, [
      { y: 0.00, rx: 1.30, rz: 0.26, sq: 0.8 },
      { y: 0.30, rx: 1.30, rz: 0.28, sq: 0.8 },
      { y: 0.42, rx: 1.15, rz: 0.24, sq: 0.8 }
    ], 10, true, true), stone);
    lintel.position.y = 2.45; lintel.userData.depthMat = depthMat;
    group.add(lintel);

    // светящаяся плоскость портала
    var portalMat = new THREE.ShaderMaterial({
      glslVersion: THREE.GLSL3, transparent: true, side: THREE.DoubleSide,
      depthWrite: false,
      uniforms: { uTime: common.uTime, uColA: { value: new THREE.Color(0.15, 1.1, 0.75) }, uColB: { value: new THREE.Color(1.4, 1.9, 0.9) } },
      vertexShader: [
        'out vec2 vUv; out vec3 vW;',
        'void main(){ vUv = uv; vec4 wp = modelMatrix * vec4(position,1.0); vW = wp.xyz;',
        '  gl_Position = projectionMatrix * viewMatrix * wp; }'
      ].join('\n'),
      fragmentShader: [
        'precision highp float; in vec2 vUv; in vec3 vW; out vec4 outColor;',
        'uniform float uTime; uniform vec3 uColA, uColB;',
        'float h12(vec2 p){ vec3 p3=fract(vec3(p.xyx)*0.1031); p3+=dot(p3,p3.yzx+33.33); return fract((p3.x+p3.y)*p3.z); }',
        'float vn(vec2 p){ vec2 i=floor(p), f=fract(p); f=f*f*(3.-2.*f);',
        '  return mix(mix(h12(i),h12(i+vec2(1,0)),f.x), mix(h12(i+vec2(0,1)),h12(i+vec2(1,1)),f.x), f.y); }',
        'float fb(vec2 p){ float s=0.,a=.5; for(int i=0;i<5;i++){ s+=a*vn(p); p*=2.1; a*=.5; } return s; }',
        'void main(){',
        '  vec2 c = vUv - 0.5;',
        '  float r = length(c * vec2(1.15, 1.0));',
        '  float ang = atan(c.y, c.x);',
        // закрученные вихри
        '  float sw = fb(vec2(ang * 1.6 + r * 5.0 - uTime * 0.8, r * 7.0 + uTime * 0.35));',
        '  float sw2 = fb(vec2(ang * 2.4 - r * 3.0 + uTime * 0.5, r * 11.0 - uTime * 0.6));',
        '  float body = smoothstep(0.5, 0.12, r);',
        '  float glow = smoothstep(0.52, 0.30, r) * (0.55 + 0.75 * sw);',
        '  vec3 col = mix(uColA, uColB, sw2 * 0.85);',
        '  col *= (0.55 + 1.35 * glow);',
        '  float ring = smoothstep(0.03, 0.0, abs(r - 0.46)) * 2.2;',
        '  col += uColB * ring;',
        '  float a = clamp(body * (0.45 + 0.75 * sw) + ring * 0.8, 0.0, 1.0);',
        '  outColor = vec4(col, a);',
        '}'
      ].join('\n')
    });
    var portal = new THREE.Mesh(new THREE.PlaneGeometry(1.75, 2.35), portalMat);
    portal.position.y = 1.25;
    group.add(portal);

    // золотая арка-подсветка
    var arch = new THREE.Mesh(new THREE.TorusGeometry(0.95, 0.05, 8, 24, Math.PI), gold);
    arch.position.y = 2.28; arch.userData.depthMat = depthMat;
    group.add(arch);

    return { group: group, portal: portal, material: portalMat };
  }

  // ---- монета ------------------------------------------------------
  function makeCoinGeometry(THREE) {
    var g = new THREE.CylinderGeometry(0.19, 0.19, 0.035, 18, 1);
    g.rotateX(Math.PI / 2);
    return g;
  }

  function makeCoins(THREE, common, positions, depthMat) {
    var geo = makeCoinGeometry(THREE);
    var mat = global.MAT.makeObjectMaterial(THREE, common, {
      color: 0xe8c14a, rough: 0.22, metal: 0.95, emissive: 0x6a4a00, emissiveAmt: 0.35
    });
    var mesh = new THREE.InstancedMesh(geo, mat, Math.max(1, positions.length));
    mesh.userData.depthMat = depthMat;
    mesh.instanceMatrix.setUsage(THREE.DynamicDrawUsage);
    mesh.count = positions.length;
    mesh.frustumCulled = false;
    return { mesh: mesh, mat: mat, geo: geo };
  }

  // ---- фонарь ------------------------------------------------------
  function makeLantern(THREE, common, depthMat) {
    var group = new THREE.Object3D();
    var MAT = global.MAT;
    var wood = MAT.makeObjectMaterial(THREE, common, { color: 0x4a3a28, rough: 0.9, noiseAmt: 0.3, noiseScale: 14 });
    var iron = MAT.makeObjectMaterial(THREE, common, { color: 0x24242a, rough: 0.5, metal: 0.6 });
    var glass = MAT.makeObjectMaterial(THREE, common, {
      color: 0xffcf8a, rough: 0.15, emissive: 0xffb347, emissiveAmt: 6.0
    });
    var post = new THREE.Mesh(global.CHAR.loft(THREE, [
      { y: 0.0, rx: 0.10, rz: 0.10, sq: 0.4 },
      { y: 0.2, rx: 0.075, rz: 0.075, sq: 0.3 },
      { y: 2.5, rx: 0.058, rz: 0.058, sq: 0.3 }
    ], 8, true, true), wood);
    post.userData.depthMat = depthMat; group.add(post);
    var arm = new THREE.Mesh(global.CHAR.loft(THREE, [
      { y: 0.0, rx: 0.032, rz: 0.032 }, { y: 0.42, rx: 0.028, rz: 0.028 }
    ], 6, true, true), iron);
    arm.position.set(0, 2.5, 0); arm.rotation.x = Math.PI / 2; arm.userData.depthMat = depthMat;
    group.add(arm);
    var lamp = new THREE.Mesh(global.CHAR.loft(THREE, [
      { y: 0.0, rx: 0.10, rz: 0.10, sq: 0.7 },
      { y: 0.16, rx: 0.13, rz: 0.13, sq: 0.7 },
      { y: 0.34, rx: 0.11, rz: 0.11, sq: 0.7 },
      { y: 0.40, rx: 0.05, rz: 0.05, sq: 0.5 }
    ], 8, true, true), glass);
    lamp.position.set(0, 2.10, 0.42);
    group.add(lamp);
    return { group: group, lamp: lamp };
  }

  // Много фонарей — тремя инстансовыми мешами вместо сотен объектов
  function makeLanterns(THREE, common, positions, depthMat) {
    var MAT = global.MAT;
    var wood = MAT.makeObjectMaterial(THREE, common, { color: 0x4a3a28, rough: 0.9, noiseAmt: 0.3, noiseScale: 14 });
    var iron = MAT.makeObjectMaterial(THREE, common, { color: 0x24242a, rough: 0.5, metal: 0.6 });
    var glass = MAT.makeObjectMaterial(THREE, common, { color: 0xffcf8a, rough: 0.15, emissive: 0xffb347, emissiveAmt: 7.0 });
    var postG = global.CHAR.loft(THREE, [
      { y: 0.0, rx: 0.10, rz: 0.10, sq: 0.4 },
      { y: 0.2, rx: 0.075, rz: 0.075, sq: 0.3 },
      { y: 2.5, rx: 0.058, rz: 0.058, sq: 0.3 }
    ], 8, true, true);
    var armG = global.CHAR.loft(THREE, [
      { y: 0.0, rx: 0.032, rz: 0.032 }, { y: 0.42, rx: 0.028, rz: 0.028 }
    ], 6, true, true).rotateX(Math.PI / 2).translate(0, 2.5, 0);
    var lampG = global.CHAR.loft(THREE, [
      { y: 0.0, rx: 0.10, rz: 0.10, sq: 0.7 },
      { y: 0.16, rx: 0.13, rz: 0.13, sq: 0.7 },
      { y: 0.34, rx: 0.11, rz: 0.11, sq: 0.7 },
      { y: 0.40, rx: 0.05, rz: 0.05, sq: 0.5 }
    ], 8, true, true).translate(0, 2.10, 0.42);
    var n = Math.max(1, positions.length);
    var meshes = [];
    [[postG, wood], [armG, iron], [lampG, glass]].forEach(function (pair, i) {
      var m = new THREE.InstancedMesh(pair[0], pair[1], n);
      if (i !== 2) m.userData.depthMat = depthMat;   // стекло тень не отбрасывает
      m.count = positions.length;
      m.frustumCulled = false;
      var d = new THREE.Object3D();
      for (var k = 0; k < positions.length; k++) {
        d.position.set(positions[k].x, positions[k].y, positions[k].z);
        d.rotation.y = positions[k].rot || 0;
        d.updateMatrix();
        m.setMatrixAt(k, d.matrix);
      }
      m.instanceMatrix.needsUpdate = true;
      meshes.push(m);
    });
    return meshes;
  }

  global.PROPS = { makeExit: makeExit, makeCoins: makeCoins, makeLantern: makeLantern, makeLanterns: makeLanterns, makeCoinGeometry: makeCoinGeometry };
})(window);
