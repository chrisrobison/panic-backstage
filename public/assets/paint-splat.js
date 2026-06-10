// ── <paint-splat> web component ──────────────────────────────────────────────
// A self-contained, attribute-driven generative paint-splatter canvas. Importing
// this module registers the <paint-splat> custom element (registration is the
// side effect — there is nothing to call). Used as the decorative background
// behind the event title in the workspace summary's `.flyer` card.
//
// The canvas fills its host element (width/height 100%); set the host's size via
// CSS and the canvas drawing resolution via the width/height attributes.
//
//   <paint-splat width="520" height="320" bg-color="#141414"
//                interactive="false" wall-texture="false"></paint-splat>
//
// Attributes: width, height, bg-color, wall-texture, interactive,
//   big-min/big-max, mid-min/mid-max, small-min/small-max,
//   drip-min/drip-max, drip-count, spatter-density.
// Child tags: <splat-config attr="…">, <splat-palette colors="#hex #hex …">.
// JS API: el.regenerate(), el.toDataURL(type?), el.download(filename?).

class PaintSplat extends HTMLElement {

  static get observedAttributes() {
    return [
      'width','height',
      'big-count','mid-count','small-count',
      'drip-length','spatter-density',
      'interactive','seed',
      'bg-color','wall-texture'
    ];
  }

  constructor() {
    super();
    this.attachShadow({ mode: 'open' });
    this._canvas = document.createElement('canvas');
    this._canvas.style.cssText = 'display:block;width:100%;height:100%;';
    this.shadowRoot.appendChild(this._canvas);
    this._ctx = this._canvas.getContext('2d');
  }

  connectedCallback() {
    this._applyConfig();
    this._generate();
    this._canvas.addEventListener('click', () => {
      if (this._interactive) this._generate();
    });
    // watch for child config/palette tags added late
    this._observer = new MutationObserver(() => { this._applyConfig(); this._generate(); });
    this._observer.observe(this, { childList: true, subtree: true, characterData: true });
  }

  disconnectedCallback() {
    this._observer && this._observer.disconnect();
  }

  attributeChangedCallback() {
    if (this._ctx) { this._applyConfig(); this._generate(); }
  }

  // ── config resolution (attrs + child tags) ──────────────────

  _applyConfig() {
    const a = n => this.getAttribute(n);

    // child <splat-config> overrides attrs for non-color settings
    const cfgEl = this.querySelector('splat-config');
    const ca    = cfgEl ? n => cfgEl.getAttribute(n) || a(n) : a;

    this._W             = parseInt(ca('width'))          || 800;
    this._H             = parseInt(ca('height'))         || 560;
    this._bigMin        = parseInt(ca('big-min'))        || 4;
    this._bigMax        = parseInt(ca('big-max'))        || 7;
    this._midMin        = parseInt(ca('mid-min'))        || 10;
    this._midMax        = parseInt(ca('mid-max'))        || 16;
    this._smallMin      = parseInt(ca('small-min'))      || 14;
    this._smallMax      = parseInt(ca('small-max'))      || 22;
    this._dripMin       = parseInt(ca('drip-min'))       || 25;
    this._dripMax       = parseInt(ca('drip-max'))       || 180;
    this._dripCount     = parseInt(ca('drip-count'))     || 0; // 0=auto
    this._spatterDensity= parseFloat(ca('spatter-density')) || 1.0;
    this._interactive   = ca('interactive') !== 'false';
    this._bgColor       = ca('bg-color')   || '#ede8df';
    this._wallTexture   = ca('wall-texture') !== 'false';

    this._canvas.width  = this._W;
    this._canvas.height = this._H;

    // palette: child <splat-palette> colors="..." OR built-ins
    const palEl = this.querySelector('splat-palette');
    if (palEl) {
      const raw = palEl.getAttribute('colors') || palEl.textContent;
      this._palette = raw.split(/[\s,]+/).map(s=>s.trim()).filter(s=>s.length>2);
    } else {
      const PALETTES = [
        ['#e63946','#2a9d8f','#e9c46a','#f4a261','#264653','#a8dadc','#e76f51','#457b9d'],
        ['#ff006e','#3a86ff','#ffbe0b','#8338ec','#06d6a0','#fb5607','#ff9f1c','#2ec4b6'],
        ['#d62828','#023e8a','#f77f00','#2dc653','#7b2d8b','#00b4d8','#e9c46a','#ef233c'],
        ['#ff595e','#ffca3a','#6a4c93','#1982c4','#8ac926','#ff924c','#52b788','#e07a5f'],
        ['#e040fb','#00bcd4','#ff6f00','#76ff03','#f50057','#2979ff','#ffd166','#06d6a0'],
      ];
      this._palette = PALETTES[this._rndInt(0, PALETTES.length-1)];
    }
  }

  // ── RNG helpers ──────────────────────────────────────────────

  _rnd(a,b)    { return a + Math.random()*(b-a); }
  _rndInt(a,b) { return Math.floor(this._rnd(a, b+1)); }
  _pick(arr)   { return arr[this._rndInt(0, arr.length-1)]; }

  // ── drawing primitives ────────────────────────────────────────

  _drawSplat(cx, cy, radius, color) {
    const { _ctx: ctx } = this;
    const pts = this._rndInt(10, 20);
    ctx.save();
    ctx.fillStyle = color;
    ctx.beginPath();
    for (let i=0; i<pts; i++) {
      const angle = (i/pts)*Math.PI*2;
      const r     = radius * this._rnd(0.5,1.0) * this._rnd(0.85,1.15);
      const a     = angle + this._rnd(-0.35, 0.35);
      const x     = cx + Math.cos(a)*r;
      const y     = cy + Math.sin(a)*r*this._rnd(0.65,1.0);
      i===0 ? ctx.moveTo(x,y) : ctx.lineTo(x,y);
    }
    ctx.closePath();
    ctx.fill();
    // inner highlight
    ctx.fillStyle = 'rgba(255,255,255,0.12)';
    ctx.beginPath();
    const hr = radius*0.38;
    for (let i=0; i<8; i++) {
      const a = (i/8)*Math.PI*2;
      const r = hr*this._rnd(0.7,1.0);
      const x = cx + Math.cos(a)*r*0.65;
      const y = cy - radius*0.12 + Math.sin(a)*r*0.55;
      i===0 ? ctx.moveTo(x,y) : ctx.lineTo(x,y);
    }
    ctx.closePath();
    ctx.fill();
    ctx.restore();
  }

  _drawBlobs(cx, cy, radius, color) {
    const { _ctx: ctx } = this;
    const count = this._rndInt(6,14);
    for (let i=0; i<count; i++) {
      const a  = this._rnd(0, Math.PI*2);
      const d  = this._rnd(radius*0.4, radius*1.6);
      const r  = this._rnd(radius*0.08, radius*0.4);
      const bx = cx + Math.cos(a)*d;
      const by = cy + Math.sin(a)*d*0.85;
      ctx.save();
      ctx.fillStyle   = color;
      ctx.globalAlpha = this._rnd(0.5, 0.95);
      ctx.beginPath();
      ctx.ellipse(bx, by, r, r*this._rnd(0.6,1.4), this._rnd(0,Math.PI), 0, Math.PI*2);
      ctx.fill();
      ctx.restore();
    }
  }

  _drawDrip(cx, topY, color) {
    const { _ctx: ctx } = this;
    const numDrips = this._dripCount > 0
      ? this._dripCount
      : this._rndInt(2, 7);
    for (let d=0; d<numDrips; d++) {
      const ox     = cx + this._rnd(-50, 50);
      const width  = this._rnd(3, 16);
      const length = this._rnd(this._dripMin, this._dripMax);
      const wobble = this._rnd(-10, 10);
      ctx.save();
      ctx.fillStyle   = color;
      ctx.globalAlpha = this._rnd(0.65, 1.0);
      ctx.beginPath();
      ctx.moveTo(ox - width/2, topY);
      ctx.bezierCurveTo(
        ox-width/2+wobble, topY+length*0.3,
        ox-width/3+wobble, topY+length*0.7,
        ox+wobble,         topY+length
      );
      ctx.bezierCurveTo(
        ox+width/3+wobble, topY+length*0.7,
        ox+width/2+wobble, topY+length*0.3,
        ox+width/2,        topY
      );
      ctx.closePath();
      ctx.fill();
      ctx.beginPath();
      ctx.ellipse(ox+wobble, topY+length, width*0.5, width*0.65, 0, 0, Math.PI*2);
      ctx.fill();
      ctx.restore();
    }
  }

  _drawSpatter(cx, cy, radius, color) {
    const { _ctx: ctx, _W: W, _H: H } = this;
    const base  = this._rndInt(20, 55);
    const count = Math.round(base * this._spatterDensity);
    for (let i=0; i<count; i++) {
      const a  = this._rnd(0, Math.PI*2);
      const d  = this._rnd(radius*0.6, radius*2.5);
      const r  = this._rnd(0.5, 8);
      const sx = cx + Math.cos(a)*d;
      const sy = cy + Math.sin(a)*d*0.8;
      if (sx<-20||sx>W+20||sy<-20||sy>H+20) continue;
      ctx.save();
      ctx.fillStyle   = color;
      ctx.globalAlpha = this._rnd(0.3, 0.9);
      ctx.beginPath();
      if (Math.random() < 0.4) {
        ctx.ellipse(sx, sy, r, this._rnd(4,24), a+this._rnd(-0.3,0.3), 0, Math.PI*2);
      } else {
        ctx.arc(sx, sy, r, 0, Math.PI*2);
      }
      ctx.fill();
      ctx.restore();
    }
  }

  // ── full scene render ─────────────────────────────────────────

  _generate() {
    const { _ctx: ctx, _W: W, _H: H, _palette: palette } = this;

    // background
    ctx.fillStyle = this._bgColor;
    ctx.fillRect(0, 0, W, H);

    // wall texture
    if (this._wallTexture) {
      ctx.save();
      ctx.globalAlpha = 0.035;
      for (let i=0; i<5000; i++) {
        ctx.fillStyle = Math.random() < 0.5 ? '#000' : '#fff';
        ctx.fillRect(this._rnd(0,W), this._rnd(0,H), this._rnd(1,3), this._rnd(1,3));
      }
      ctx.restore();
    }

    // big bg splats
    const bigCount = this._rndInt(this._bigMin, this._bigMax);
    for (let i=0; i<bigCount; i++) {
      const cx     = this._rnd(-20, W+20);
      const cy     = this._rnd(-20, H*0.85);
      const radius = this._rnd(120, 220);
      const color  = this._pick(palette);
      ctx.save();
      ctx.globalAlpha = this._rnd(0.55, 0.8);
      this._drawSpatter(cx, cy, radius, color);
      this._drawBlobs(cx, cy, radius, color);
      this._drawSplat(cx, cy, radius, color);
      this._drawDrip(cx, cy+radius*0.45, color);
      ctx.restore();
    }

    // medium splats
    const midCount = this._rndInt(this._midMin, this._midMax);
    for (let i=0; i<midCount; i++) {
      const cx     = this._rnd(0, W);
      const cy     = this._rnd(0, H-80);
      const radius = this._rnd(55, 120);
      const color  = this._pick(palette);
      this._drawSpatter(cx, cy, radius, color);
      this._drawBlobs(cx, cy, radius, color);
      this._drawSplat(cx, cy, radius, color);
      this._drawDrip(cx, cy+radius*0.5, color);
    }

    // small accent splats
    const smallCount = this._rndInt(this._smallMin, this._smallMax);
    for (let i=0; i<smallCount; i++) {
      const cx     = this._rnd(0, W);
      const cy     = this._rnd(0, H-40);
      const radius = this._rnd(18, 55);
      const color  = this._pick(palette);
      this._drawSpatter(cx, cy, radius, color);
      this._drawBlobs(cx, cy, radius, color);
      this._drawSplat(cx, cy, radius, color);
      if (Math.random() < 0.6) this._drawDrip(cx, cy+radius*0.5, color);
    }

    // micro chaos
    const microCount = Math.round(300 * this._spatterDensity);
    ctx.save();
    for (let i=0; i<microCount; i++) {
      ctx.fillStyle   = this._pick(palette);
      ctx.globalAlpha = this._rnd(0.25, 0.8);
      ctx.beginPath();
      if (Math.random() < 0.35) {
        ctx.ellipse(this._rnd(0,W), this._rnd(0,H), this._rnd(1,5), this._rnd(3,18), this._rnd(0,Math.PI), 0, Math.PI*2);
      } else {
        ctx.arc(this._rnd(0,W), this._rnd(0,H), this._rnd(0.5,4), 0, Math.PI*2);
      }
      ctx.fill();
    }
    ctx.restore();

    // dispatch event so parent can hook in
    this.dispatchEvent(new CustomEvent('splat-generated', { bubbles: true }));
  }

  // ── public API ───────────────────────────────────────────────

  /** Programmatically trigger a new splat */
  regenerate() { this._applyConfig(); this._generate(); }

  /** Get the canvas as a data URL */
  toDataURL(type='image/png') { return this._canvas.toDataURL(type); }

  /** Download the current canvas as a PNG */
  download(filename='paint-splat.png') {
    const a = document.createElement('a');
    a.href     = this.toDataURL();
    a.download = filename;
    a.click();
  }
}

if (!customElements.get('paint-splat')) {
  customElements.define('paint-splat', PaintSplat);
}

export { PaintSplat };
