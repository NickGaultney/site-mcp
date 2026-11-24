/*
 * Analytics dashboard chart: one measure per day as a line over a light area, with a crosshair and
 * tooltip on hover (and on keyboard focus). The Visitors / Page views buttons switch the measure.
 * The same numbers are in the "Show as a table" view under the chart.
 */
(function () {
  'use strict';
  var box = document.querySelector('.smcpa-chart');
  if (!box) return;
  var series = JSON.parse(box.getAttribute('data-series') || '[]');
  var metric = 'visitors';
  var names = { visitors: 'Visitors', pageviews: 'Page views' };
  var units = { visitors: ['visitor', 'visitors'], pageviews: ['page view', 'page views'] };
  var count = function (v, m) { return num.format(v) + ' ' + units[m][v === 1 ? 0 : 1]; };
  var NS = 'http://www.w3.org/2000/svg';
  var dateFmt = new Intl.DateTimeFormat(undefined, { month: 'short', day: 'numeric' });
  var longFmt = new Intl.DateTimeFormat(undefined, { weekday: 'short', month: 'short', day: 'numeric', year: 'numeric' });
  var num = new Intl.NumberFormat();
  var day = function (s) { return new Date(s + 'T12:00:00'); };

  function el(name, attrs, parent) {
    var n = document.createElementNS(NS, name);
    for (var k in attrs) n.setAttribute(k, attrs[k]);
    if (parent) parent.appendChild(n);
    return n;
  }

  /** A "nice" axis maximum and step: 1, 2 or 5 times a power of ten. */
  function niceScale(max) {
    if (max <= 0) return { max: 4, step: 1 };
    var rough = max / 4, pow = Math.pow(10, Math.floor(Math.log10(rough))), step = pow;
    [1, 2, 5, 10].some(function (m) { step = m * pow; return step >= rough; });
    step = Math.max(1, step); // counts: whole numbers only
    return { max: Math.ceil(max / step) * step, step: step };
  }

  function draw() {
    box.textContent = '';
    var W = box.clientWidth || 800, H = 260, m = { t: 12, r: 12, b: 28, l: 44 };
    var iw = W - m.l - m.r, ih = H - m.t - m.b, n = series.length;
    var values = series.map(function (d) { return d[metric]; });
    var s = niceScale(Math.max.apply(null, values.concat([0])));
    var x = function (i) { return m.l + (n < 2 ? iw / 2 : (i * iw) / (n - 1)); };
    var y = function (v) { return m.t + ih - (v / s.max) * ih; };
    var svg = el('svg', { width: W, height: H, viewBox: '0 0 ' + W + ' ' + H, role: 'img', 'aria-label': names[metric] + ' per day' }, box);

    for (var v = 0; v <= s.max; v += s.step) {
      el('line', { x1: m.l, x2: W - m.r, y1: y(v), y2: y(v), class: v === 0 ? 'smcpa-axis' : 'smcpa-gridline' }, svg);
      el('text', { x: m.l - 8, y: y(v) + 4, 'text-anchor': 'end', class: 'smcpa-tick' }, svg).textContent = num.format(v);
    }
    var ticks = Math.min(n, Math.max(2, Math.floor(iw / 90)));
    for (var k = 0; k < ticks; k++) {
      var i = Math.round((k * (n - 1)) / Math.max(1, ticks - 1));
      el('text', { x: x(i), y: H - 8, 'text-anchor': k === 0 ? 'start' : k === ticks - 1 ? 'end' : 'middle', class: 'smcpa-tick' }, svg).textContent = dateFmt.format(day(series[i].day));
    }

    var line = values.map(function (v, i) { return (i ? 'L' : 'M') + x(i).toFixed(1) + ' ' + y(v).toFixed(1); }).join('');
    if (n) {
      el('path', { d: line + 'L' + x(n - 1) + ' ' + y(0) + 'L' + x(0) + ' ' + y(0) + 'Z', class: 'smcpa-area' }, svg);
      el('path', { d: line, class: 'smcpa-line' }, svg);
    }

    var cross = el('line', { y1: m.t, y2: m.t + ih, class: 'smcpa-cross', visibility: 'hidden' }, svg);
    var dot = el('circle', { r: 5, class: 'smcpa-dot', visibility: 'hidden' }, svg);
    var tip = document.createElement('div');
    tip.className = 'smcpa-tip';
    tip.hidden = true;
    box.appendChild(tip);
    var hit = el('rect', { x: m.l, y: m.t, width: iw, height: ih, fill: 'transparent', tabindex: 0, 'aria-label': 'Explore ' + names[metric].toLowerCase() + ' per day with the arrow keys' }, svg);
    var cur = n - 1;

    function show(i) {
      if (!n) return;
      cur = Math.max(0, Math.min(n - 1, i));
      var d = series[cur];
      cross.setAttribute('x1', x(cur));
      cross.setAttribute('x2', x(cur));
      dot.setAttribute('cx', x(cur));
      dot.setAttribute('cy', y(d[metric]));
      cross.setAttribute('visibility', 'visible');
      dot.setAttribute('visibility', 'visible');
      var other = metric === 'visitors' ? 'pageviews' : 'visitors';
      tip.innerHTML = '<span>' + longFmt.format(day(d.day)) + '</span><br><strong>' + count(d[metric], metric) + '</strong><br><span>' + count(d[other], other) + '</span>';
      tip.hidden = false;
      var left = x(cur) + 14;
      if (left + tip.offsetWidth > W) left = x(cur) - 14 - tip.offsetWidth;
      tip.style.left = left + 'px';
      tip.style.top = Math.max(0, y(d[metric]) - tip.offsetHeight / 2) + 'px';
    }
    function hide() {
      cross.setAttribute('visibility', 'hidden');
      dot.setAttribute('visibility', 'hidden');
      tip.hidden = true;
    }
    hit.addEventListener('pointermove', function (e) {
      var r = svg.getBoundingClientRect();
      show(n < 2 ? 0 : Math.round(((e.clientX - r.left - m.l) / iw) * (n - 1)));
    });
    hit.addEventListener('pointerleave', hide);
    hit.addEventListener('focus', function () { show(cur); });
    hit.addEventListener('blur', hide);
    hit.addEventListener('keydown', function (e) {
      if (e.key === 'ArrowLeft' || e.key === 'ArrowRight') {
        e.preventDefault();
        show(cur + (e.key === 'ArrowLeft' ? -1 : 1));
      }
    });
  }

  document.querySelectorAll('.smcpa-switch button').forEach(function (b) {
    b.addEventListener('click', function () {
      metric = b.getAttribute('data-metric');
      document.querySelectorAll('.smcpa-switch button').forEach(function (o) { o.setAttribute('aria-pressed', String(o === b)); });
      document.getElementById('smcpa-chart-title').textContent = names[metric] + ' per day';
      draw();
    });
  });
  var timer;
  window.addEventListener('resize', function () { clearTimeout(timer); timer = setTimeout(draw, 150); });
  draw();
})();
