/* =====================================================================
   audio.js — процедурный звук на WebAudio: шаги, монета, сирена,
   победа, поражение, ветер. Никаких файлов.
   ===================================================================== */
(function (global) {
  'use strict';
  var ctx = null, master = null, muted = false, ambGain = null, sirenOsc = null, sirenGain = null;

  function init() {
    if (ctx) return ctx;
    var AC = window.AudioContext || window.webkitAudioContext;
    if (!AC) return null;
    ctx = new AC();
    master = ctx.createGain();
    master.gain.value = 0.55;
    master.connect(ctx.destination);
    return ctx;
  }
  function resume() { if (ctx && ctx.state === 'suspended') ctx.resume(); }

  function env(node, t0, a, d, peak) {
    var g = node.gain;
    g.setValueAtTime(0.0001, t0);
    g.exponentialRampToValueAtTime(Math.max(peak, 0.0002), t0 + a);
    g.exponentialRampToValueAtTime(0.0001, t0 + a + d);
  }

  function tone(freq, dur, type, vol, slideTo) {
    if (!ctx || muted) return;
    var t = ctx.currentTime;
    var o = ctx.createOscillator(), g = ctx.createGain();
    o.type = type || 'sine';
    o.frequency.setValueAtTime(freq, t);
    if (slideTo) o.frequency.exponentialRampToValueAtTime(slideTo, t + dur);
    env(g, t, 0.008, dur, vol === undefined ? 0.3 : vol);
    o.connect(g); g.connect(master);
    o.start(t); o.stop(t + dur + 0.05);
  }

  function noiseBurst(dur, vol, filterFreq, q) {
    if (!ctx || muted) return;
    var t = ctx.currentTime;
    var len = Math.max(1, Math.floor(ctx.sampleRate * dur));
    var buf = ctx.createBuffer(1, len, ctx.sampleRate);
    var d = buf.getChannelData(0);
    for (var i = 0; i < len; i++) d[i] = (Math.random() * 2 - 1) * (1 - i / len);
    var src = ctx.createBufferSource(); src.buffer = buf;
    var f = ctx.createBiquadFilter(); f.type = 'bandpass';
    f.frequency.value = filterFreq || 900; f.Q.value = q || 1.2;
    var g = ctx.createGain(); env(g, t, 0.005, dur, vol || 0.2);
    src.connect(f); f.connect(g); g.connect(master);
    src.start(t); src.stop(t + dur + 0.02);
  }

  var API = {
    init: init, resume: resume,
    setMuted: function (m) { muted = m; if (master) master.gain.value = m ? 0 : 0.55; },
    step: function (run) {
      noiseBurst(run ? 0.10 : 0.13, run ? 0.16 : 0.10, 320 + Math.random() * 260, 0.9);
    },
    jump: function () { tone(360, 0.16, 'triangle', 0.16, 620); },
    land: function () { noiseBurst(0.12, 0.16, 180, 0.8); },
    coin: function () {
      tone(1046, 0.09, 'square', 0.10);
      setTimeout(function () { tone(1568, 0.16, 'square', 0.09); }, 70);
    },
    win: function () {
      var seq = [523, 659, 784, 1046, 1318];
      seq.forEach(function (f, i) { setTimeout(function () { tone(f, 0.32, 'triangle', 0.20); }, i * 130); });
    },
    lose: function () {
      tone(220, 0.6, 'sawtooth', 0.16, 70);
      noiseBurst(0.5, 0.14, 200, 0.6);
    },
    whistle: function () {
      tone(2100, 0.22, 'sine', 0.12, 2600);
      setTimeout(function () { tone(2400, 0.18, 'sine', 0.10, 1900); }, 190);
    },
    siren: function (on) {
      if (!ctx || muted) return;
      if (on && !sirenOsc) {
        sirenOsc = ctx.createOscillator();
        var lfo = ctx.createOscillator(), lfoG = ctx.createGain();
        sirenGain = ctx.createGain(); sirenGain.gain.value = 0.0001;
        sirenOsc.type = 'square'; sirenOsc.frequency.value = 620;
        lfo.frequency.value = 1.6; lfoG.gain.value = 180;
        lfo.connect(lfoG); lfoG.connect(sirenOsc.frequency);
        var f = ctx.createBiquadFilter(); f.type = 'lowpass'; f.frequency.value = 1400;
        sirenOsc.connect(f); f.connect(sirenGain); sirenGain.connect(master);
        sirenOsc.start(); lfo.start();
        sirenGain.gain.exponentialRampToValueAtTime(0.06, ctx.currentTime + 0.4);
        sirenOsc._lfo = lfo;
      } else if (!on && sirenOsc) {
        var s = sirenOsc, g = sirenGain, l = sirenOsc._lfo;
        sirenOsc = null; sirenGain = null;
        g.gain.exponentialRampToValueAtTime(0.0001, ctx.currentTime + 0.35);
        setTimeout(function () { try { s.stop(); l.stop(); } catch (e) { } }, 500);
      }
    },
    ambient: function (on) {
      if (!ctx || muted) return;
      if (on && !ambGain) {
        var len = Math.floor(ctx.sampleRate * 2);
        var buf = ctx.createBuffer(1, len, ctx.sampleRate);
        var d = buf.getChannelData(0);
        var last = 0;
        for (var i = 0; i < len; i++) { last = (last * 0.98 + (Math.random() * 2 - 1) * 0.02); d[i] = last * 3.2; }
        var src = ctx.createBufferSource(); src.buffer = buf; src.loop = true;
        var f = ctx.createBiquadFilter(); f.type = 'lowpass'; f.frequency.value = 420;
        ambGain = ctx.createGain(); ambGain.gain.value = 0.0001;
        src.connect(f); f.connect(ambGain); ambGain.connect(master);
        src.start();
        ambGain.gain.exponentialRampToValueAtTime(0.075, ctx.currentTime + 2.0);
        ambGain._src = src;
      } else if (!on && ambGain) {
        var g2 = ambGain; ambGain = null;
        g2.gain.exponentialRampToValueAtTime(0.0001, ctx.currentTime + 0.6);
        setTimeout(function () { try { g2._src.stop(); } catch (e) { } }, 900);
      }
    }
  };
  global.SFX = API;
})(window);
