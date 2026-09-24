{{--
    Stilvorlage des Mitarbeiterportals — WOERTLICH aus dem abgenommenen Entwurf
    resources/mockups/crew-portal.html uebernommen, damit die Optik die ist, die
    Markus am 24.08.2026 abgenommen hat. Nicht nachgebaut, kopiert.

    Steht in einem verbatim-Block, weil Blade sonst die media- und keyframes-
    Regeln fuer eigene Direktiven haelt und sie still verschluckt.

    Enthaelt noch die Klassen der Praesentationsseite (.browser, .band, .hero,
    .feats, .gains) — die umrahmen im Entwurf das Telefon. Sie stoeren nicht und
    koennen raus, sobald das Portal fuer sich steht.
--}}
@verbatim
<style>
:root{
  --brand:#22a8b8; --brand-hover:#1e97a6; --brand-mid:#167582; --brand-deep:#0e535e;
  --brand-light:#90dce4; --brand-tint:#E4F5F7;
  --ink:#111827; --ink-2:#374151; --ink-3:#6b7280;
  --line:#e5e7eb; --line-2:#d1d5db;
  --surface:#ffffff; --surface-2:#f9fafb; --surface-3:#f3f4f6;
  --ground:#EDF1F2;
  --ok:#15803d; --ok-bg:#DCF3E4;
  --warn:#9a5b06; --warn-bg:#FBEEDA;
  --crit:#a4261d; --crit-bg:#FAE6E3;
  --bezel:#0B1416; --bezel-line:#22343A;
  --shadow:0 30px 70px -26px rgba(17,24,39,.45), 0 4px 14px -6px rgba(17,24,39,.14);
  --r:8px; --r-card:10px;
  --display:"Chau Philomene One",ui-sans-serif,system-ui,sans-serif;
  --body:"Inter",ui-sans-serif,system-ui,-apple-system,sans-serif;
}
@media (prefers-color-scheme: dark){
  :root:not([data-theme="light"]){
    --brand:#22a8b8; --brand-hover:#3fbccb; --brand-mid:#3fbccb; --brand-deep:#0b3f48;
    --brand-light:#90dce4; --brand-tint:#0E2F35;
    --ink:#F3F5F6; --ink-2:#B6C0C4; --ink-3:#8A959A;
    --line:#243237; --line-2:#32444A;
    --surface:#131E21; --surface-2:#18262A; --surface-3:#1F2F34;
    --ground:#0A1214;
    --ok:#4ec288; --ok-bg:#123024;
    --warn:#dda85e; --warn-bg:#31260F;
    --crit:#eb8279; --crit-bg:#331A18;
    --shadow:0 30px 70px -26px rgba(0,0,0,.8), 0 4px 14px -6px rgba(0,0,0,.5);
  }
}
:root[data-theme="dark"]{
  --brand:#22a8b8; --brand-hover:#3fbccb; --brand-mid:#3fbccb; --brand-deep:#0b3f48;
  --brand-light:#90dce4; --brand-tint:#0E2F35;
  --ink:#F3F5F6; --ink-2:#B6C0C4; --ink-3:#8A959A;
  --line:#243237; --line-2:#32444A;
  --surface:#131E21; --surface-2:#18262A; --surface-3:#1F2F34;
  --ground:#0A1214;
  --ok:#4ec288; --ok-bg:#123024;
  --warn:#dda85e; --warn-bg:#31260F;
  --crit:#eb8279; --crit-bg:#331A18;
  --shadow:0 30px 70px -26px rgba(0,0,0,.8), 0 4px 14px -6px rgba(0,0,0,.5);
}

*{box-sizing:border-box}
body{margin:0; background:var(--ground); color:var(--ink); font-family:var(--body); font-size:16px; line-height:1.6; -webkit-font-smoothing:antialiased}
h1,h2,h3,h4{font-family:var(--display); font-weight:400; text-wrap:balance; margin:0; line-height:1.1}
p{margin:0}
:focus-visible{outline:2px solid var(--brand); outline-offset:2px; border-radius:6px}
.wrap{max-width:1120px; margin:0 auto; padding:0 24px}

.reveal{opacity:0; transform:translateY(16px); transition:opacity .6s ease, transform .6s ease}
.reveal.in{opacity:1; transform:none}
@media (prefers-reduced-motion: reduce){
  *{animation:none !important; transition:none !important}
  .reveal{opacity:1; transform:none}
}

/* ---------- HERO ---------- */
.hero{background:var(--brand-deep); color:#fff; position:relative; overflow:hidden}
.hero::after{
  content:""; position:absolute; inset:auto -10% -55% 40%; height:70%;
  background:radial-gradient(closest-side, rgba(144,220,228,.22), transparent 70%);
  pointer-events:none;
}
.hero .wrap{display:grid; grid-template-columns:minmax(0,1fr) auto; gap:56px; align-items:center; padding-top:74px; padding-bottom:74px; position:relative; z-index:1}
@media (max-width:940px){.hero .wrap{grid-template-columns:1fr; gap:44px; padding-top:56px; padding-bottom:56px}}
.hero .eyebrow{font-size:12px; font-weight:700; letter-spacing:.16em; text-transform:uppercase; color:var(--brand-light)}
.hero h1{font-size:clamp(40px,6.4vw,72px); letter-spacing:.005em; margin-top:18px}
.hero .sub{font-size:clamp(17px,2vw,20px); opacity:.9; margin-top:20px; max-width:46ch}
.hero .pills{display:flex; flex-wrap:wrap; gap:9px; margin-top:28px}
.hero .pill{background:rgba(255,255,255,.12); border:1px solid rgba(255,255,255,.22); border-radius:999px; padding:7px 15px; font-size:13.5px; font-weight:600}
.hero .note{margin-top:26px; font-size:13px; opacity:.62; max-width:44ch}

/* ---------- TELEFON ---------- */
.phone{width:352px; background:var(--bezel); border:1px solid var(--bezel-line); border-radius:44px; padding:10px; box-shadow:var(--shadow); flex:none}
@media (max-width:940px){.phone{margin:0 auto}}
@media (max-width:420px){.phone{width:100%; max-width:340px}}
.screen{background:var(--surface); border-radius:35px; height:690px; overflow:hidden; display:flex; flex-direction:column; color:var(--ink)}
.statusbar{display:flex; justify-content:space-between; padding:11px 21px 3px; font-size:11.5px; font-weight:600; color:var(--ink-2); font-variant-numeric:tabular-nums}
.appbar{padding:5px 19px 11px; display:flex; align-items:center; justify-content:space-between; border-bottom:1px solid var(--line)}
.wordmark{font-family:var(--display); font-size:16px}
.wordmark span{color:var(--brand)}
.avatar{width:31px; height:31px; border-radius:50%; background:var(--brand); color:#fff; display:grid; place-items:center; font-size:12px; font-weight:700}
.scroll{flex:1; overflow-y:auto; padding:17px 19px 24px; display:flex; flex-direction:column; gap:17px}
.scroll::-webkit-scrollbar{width:0}

.greet h2{font-size:25px}
.greet p{color:var(--ink-2); font-size:13.5px; margin-top:3px}
.sec-label{font-size:10.5px; font-weight:700; letter-spacing:.12em; text-transform:uppercase; color:var(--ink-3); display:flex; align-items:center; gap:8px}
.sec-label .count{background:var(--surface-3); color:var(--ink-2); border-radius:999px; padding:1px 7px; letter-spacing:0}
.card{background:var(--surface); border:1px solid var(--line); border-radius:var(--r-card); overflow:hidden}
.card.flat{background:var(--surface-2)}

.next{background:var(--brand-deep); color:#fff; border-radius:var(--r-card); padding:17px}
.next .kicker{font-size:10.5px; font-weight:700; letter-spacing:.12em; text-transform:uppercase; opacity:.75}
.next h3{font-size:22px; margin-top:7px}
.next .when{font-size:13.5px; opacity:.82; margin-top:4px; font-variant-numeric:tabular-nums}
.beacon{margin-top:13px; background:rgba(255,255,255,.12); border:1px solid rgba(255,255,255,.2); border-radius:var(--r); padding:10px 12px; display:flex; align-items:center; gap:11px}
.beacon .big{font-family:var(--display); font-size:25px; line-height:1; color:var(--brand-light); font-variant-numeric:tabular-nums}
.beacon .lbl{font-size:12px; opacity:.85; line-height:1.35}
.next .meta{display:flex; gap:15px; margin-top:12px; font-size:12.5px; opacity:.85; flex-wrap:wrap}
.btn-confirm{width:100%; margin-top:14px; border:none; border-radius:var(--r); padding:13px; background:var(--brand-light); color:#08343B; font-family:var(--display); font-size:16.5px; cursor:pointer; transition:filter .12s ease, transform .12s ease}
.btn-confirm:hover{filter:brightness(1.05)} .btn-confirm:active{transform:scale(.985)}
.btn-confirm.done{background:rgba(255,255,255,.16); color:#fff; cursor:default}
.deadline{font-size:11px; opacity:.75; text-align:center; margin-top:7px}

.task{display:flex; gap:11px; align-items:flex-start; padding:12px 13px; border-bottom:1px solid var(--line)}
.task:last-child{border-bottom:none}
.dot{width:9px; height:9px; border-radius:50%; margin-top:6px; flex:none}
.dot.warn{background:var(--warn)} .dot.crit{background:var(--crit)} .dot.ok{background:var(--ok)}
.task .t{font-size:14px; font-weight:600; line-height:1.3}
.task .s{font-size:12px; color:var(--ink-2); margin-top:2px}
.chev{margin-left:auto; color:var(--ink-3); font-size:17px; align-self:center}

.chip{display:inline-flex; align-items:center; gap:5px; font-size:11px; font-weight:700; padding:3px 9px; border-radius:999px}
.chip.ok{background:var(--ok-bg); color:var(--ok)}
.chip.warn{background:var(--warn-bg); color:var(--warn)}
.chip.crit{background:var(--crit-bg); color:var(--crit)}
.chip.info{background:var(--brand-tint); color:var(--brand-mid)}

.ev-head{padding:13px; border-bottom:1px solid var(--line); background:var(--surface-2)}
.ev-head h4{font-size:18px}
.ev-head .loc{font-size:12px; color:var(--ink-2); margin-top:3px}
.day{display:flex; align-items:center; gap:11px; padding:11px 13px; border-bottom:1px solid var(--line)}
.datebox{width:44px; flex:none; text-align:center; border-radius:var(--r); background:var(--brand-tint); padding:5px 0; line-height:1.1}
.datebox .d{font-family:var(--display); font-size:18px; color:var(--brand-mid); font-variant-numeric:tabular-nums}
.datebox .m{font-size:9.5px; font-weight:700; text-transform:uppercase; color:var(--brand-mid); letter-spacing:.06em}
.day .info .l1{font-size:13.5px; font-weight:600}
.day .info .l2{font-size:12px; color:var(--ink-2); font-variant-numeric:tabular-nums}
.ev-foot{padding:11px 13px; display:flex; flex-direction:column; gap:8px}
.btn{border:1px solid var(--line-2); background:var(--surface); color:var(--ink); border-radius:var(--r); padding:10px; font-family:var(--body); font-size:13.5px; font-weight:600; cursor:pointer; width:100%; display:flex; align-items:center; justify-content:center; gap:8px}
.btn:hover{background:var(--surface-2)}
.btn.primary{background:var(--brand); color:#fff; border-color:var(--brand)}
.btn.primary:hover{background:var(--brand-hover)}
.panel{background:var(--surface-2); border-radius:var(--r); padding:10px 12px}
.panel .h{font-size:10px; font-weight:700; letter-spacing:.1em; text-transform:uppercase; color:var(--ink-3)}
.panel .b{font-size:13px; margin-top:4px; line-height:1.45}

.doc{display:flex; gap:11px; padding:13px; border-bottom:1px solid var(--line); align-items:flex-start}
.doc:last-child{border-bottom:none}
.docicon{width:36px; height:42px; flex:none; border-radius:5px; border:1px solid var(--line-2); background:var(--surface-2); display:grid; place-items:end center; padding-bottom:5px}
.docicon span{font-size:8px; font-weight:700; color:var(--ink-3)}
.doc .body{min-width:0; flex:1}
.doc .title{font-size:14px; font-weight:600; line-height:1.3}
.doc .sub{font-size:12px; color:var(--ink-2); margin-top:3px}
.doc .row{display:flex; align-items:center; gap:6px; margin-top:8px; flex-wrap:wrap}
.mini{border:1px solid var(--line-2); background:var(--surface); border-radius:var(--r); padding:6px 10px; font-size:12px; font-weight:600; font-family:var(--body); cursor:pointer}
.mini.primary{background:var(--brand); color:#fff; border-color:var(--brand)}

.ring-row{display:flex; align-items:center; gap:15px; padding:15px; background:var(--surface-2); border-radius:var(--r-card)}
.ring{width:62px; height:62px; flex:none; border-radius:50%; background:conic-gradient(var(--brand) 0 85%, var(--surface-3) 85% 100%); display:grid; place-items:center; position:relative}
.ring::after{content:""; position:absolute; inset:7px; background:var(--surface-2); border-radius:50%}
.ring b{position:relative; z-index:1; font-family:var(--display); font-weight:400; font-size:16px}
.ring-row .txt .t{font-size:15px; font-family:var(--display)}
.ring-row .txt .s{font-size:12.5px; color:var(--ink-2); margin-top:3px}
.grouprow{display:flex; align-items:center; gap:11px; padding:12px 13px; border-bottom:1px solid var(--line)}
.grouprow:last-child{border-bottom:none}
.grouprow .n{font-size:14px; font-weight:600}
.grouprow .v{font-size:12px; color:var(--ink-2)}
.uploads{display:grid; grid-template-columns:1fr 1fr; gap:8px}
.up{border:1px dashed var(--line-2); border-radius:var(--r); padding:11px 9px; text-align:center; background:var(--surface-2)}
.up.filled{border-style:solid; background:var(--ok-bg); border-color:transparent}
.up .n{font-size:12px; font-weight:600; line-height:1.25}
.up .s{font-size:10.5px; margin-top:3px; color:var(--ink-2)}
.up.filled .s{color:var(--ok); font-weight:600}
.alert{display:flex; gap:10px; padding:12px; border-radius:var(--r); background:var(--warn-bg); align-items:flex-start}
.alert .txt{font-size:13px; line-height:1.4; color:var(--warn); font-weight:500}
.alert .txt b{font-weight:700}

.tabbar{display:grid; grid-template-columns:repeat(4,1fr); border-top:1px solid var(--line); background:var(--surface); padding:7px 6px 13px}
.tab{border:none; background:none; cursor:pointer; font-family:var(--body); display:flex; flex-direction:column; align-items:center; gap:4px; padding:6px 2px; color:var(--ink-3); font-size:10px; font-weight:600; border-radius:var(--r)}
.tab svg{width:20px; height:20px; stroke:currentColor; fill:none; stroke-width:1.7; stroke-linecap:round; stroke-linejoin:round}
.tab[aria-selected="true"]{color:var(--brand)}
.tab .ico{position:relative}
.tab .badge{position:absolute; transform:translate(12px,-4px); background:var(--crit); color:#fff; font-size:9px; font-weight:700; border-radius:999px; min-width:15px; padding:1px 4px; line-height:1.4}
.pane{display:none; flex-direction:column; gap:17px}
.pane.on{display:flex; animation:fade .22s ease}
@keyframes fade{from{opacity:0; transform:translateY(5px)}to{opacity:1; transform:none}}

.hint{margin-top:20px; text-align:center; font-size:13px; color:rgba(255,255,255,.72)}
@media (max-width:940px){.hint{color:var(--ink-3)}}

/* ---------- Abschnitte ---------- */
section{padding:78px 0}
.kicker{font-size:12px; font-weight:700; letter-spacing:.16em; text-transform:uppercase; color:var(--brand-mid)}
section h2{font-size:clamp(30px,4.4vw,46px); letter-spacing:.005em; margin-top:14px}
section .lede{color:var(--ink-2); font-size:18px; margin-top:16px; max-width:60ch}

.feats{display:grid; grid-template-columns:repeat(auto-fit,minmax(255px,1fr)); gap:20px; margin-top:44px}
.feat{background:var(--surface); border:1px solid var(--line); border-radius:var(--r-card); padding:26px}
.feat .ic{width:44px; height:44px; border-radius:11px; background:var(--brand-tint); color:var(--brand-mid); display:grid; place-items:center; margin-bottom:17px}
.feat .ic svg{width:23px; height:23px; stroke:currentColor; fill:none; stroke-width:1.7; stroke-linecap:round; stroke-linejoin:round}
.feat h3{font-size:21px}
.feat p{font-size:15px; color:var(--ink-2); margin-top:9px}
.feat .out{margin-top:14px; padding-top:13px; border-top:1px solid var(--line); font-size:13.5px; color:var(--brand-mid); font-weight:600}

.band{background:var(--surface); border-top:1px solid var(--line); border-bottom:1px solid var(--line)}
.gains{display:grid; grid-template-columns:repeat(auto-fit,minmax(230px,1fr)); gap:34px; margin-top:44px}
.gain .q{font-family:var(--display); font-size:21px; color:var(--brand-mid)}
.gain p{font-size:15px; color:var(--ink-2); margin-top:9px}

.deskwrap{margin-top:44px; overflow-x:auto; padding-bottom:8px}
.browser{min-width:900px; background:var(--surface); border:1px solid var(--line-2); border-radius:12px; overflow:hidden; box-shadow:var(--shadow)}
.bchrome{display:flex; align-items:center; gap:9px; padding:11px 14px; background:var(--surface-3); border-bottom:1px solid var(--line)}
.bchrome i{width:11px; height:11px; border-radius:50%; background:var(--line-2); display:block}
.burl{flex:1; margin-left:8px; background:var(--surface); border:1px solid var(--line); border-radius:999px; padding:5px 14px; font-size:12px; color:var(--ink-3); max-width:400px}
.bbody{display:grid; grid-template-columns:230px minmax(0,1fr); min-height:480px}
.brail{border-right:1px solid var(--line); padding:20px 14px; background:var(--surface-2); display:flex; flex-direction:column; gap:5px}
.brail .logo{font-family:var(--display); font-size:18px; padding:0 10px 15px}
.brail .logo span{color:var(--brand)}
.rnav{display:flex; align-items:center; gap:11px; padding:10px 12px; border-radius:var(--r); font-size:14px; font-weight:600; color:var(--ink-2)}
.rnav svg{width:18px; height:18px; stroke:currentColor; fill:none; stroke-width:1.7; stroke-linecap:round; stroke-linejoin:round}
.rnav.on{background:var(--brand-tint); color:var(--brand-mid)}
.rnav .n{margin-left:auto; background:var(--crit); color:#fff; font-size:10px; border-radius:999px; padding:1px 6px; font-weight:700}
.brail .who{margin-top:auto; display:flex; align-items:center; gap:10px; padding:12px; border-top:1px solid var(--line)}
.brail .who .nm{font-size:13px; font-weight:600}
.brail .who .sb{font-size:11px; color:var(--ink-3)}
.bmain{padding:25px 28px; display:flex; flex-direction:column; gap:19px}
.bmain h3{font-size:25px}
.bmain .bsub{color:var(--ink-2); font-size:14px; margin-top:4px}
.bnext{background:var(--brand-deep); color:#fff; border-radius:var(--r-card); padding:21px}
.bnext .kk{font-size:10.5px; font-weight:700; letter-spacing:.12em; text-transform:uppercase; opacity:.75}
.bnext h4{font-size:25px; margin-top:8px}
.bnext .when{font-size:13.5px; opacity:.82; margin-top:4px; font-variant-numeric:tabular-nums}
.grid3{display:grid; grid-template-columns:repeat(3,1fr); gap:10px; margin-top:15px}
.bmini{background:rgba(255,255,255,.12); border:1px solid rgba(255,255,255,.2); border-radius:var(--r); padding:10px 12px}
.bmini .k{font-size:10px; letter-spacing:.1em; text-transform:uppercase; opacity:.72; font-weight:700}
.bmini .v{font-family:var(--display); font-size:18px; margin-top:3px; color:var(--brand-light); font-variant-numeric:tabular-nums}
.bnext .actions{display:flex; gap:10px; margin-top:16px; flex-wrap:wrap}
.bbtn{border:none; border-radius:var(--r); padding:10px 17px; font-family:var(--display); font-size:15px; cursor:pointer; background:var(--brand-light); color:#08343B}
.bbtn.ghost{background:rgba(255,255,255,.13); color:#fff; border:1px solid rgba(255,255,255,.25); font-family:var(--body); font-weight:600; font-size:13.5px}
.bcols{display:grid; grid-template-columns:minmax(0,1.2fr) minmax(0,1fr); gap:19px; align-items:start}

.steps{display:grid; grid-template-columns:repeat(auto-fit,minmax(240px,1fr)); gap:22px; margin-top:44px}
.step{background:var(--surface); border:1px solid var(--line); border-radius:var(--r-card); padding:24px; position:relative}
.step .no{font-family:var(--display); font-size:34px; color:var(--brand-light); line-height:1}
.step h3{font-size:19px; margin-top:10px}
.step p{font-size:14.5px; color:var(--ink-2); margin-top:8px}

footer{padding:40px 0 70px; color:var(--ink-3); font-size:13.5px; border-top:1px solid var(--line)}
</style>
@endverbatim
