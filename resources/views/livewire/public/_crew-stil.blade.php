<div wire:ignore>
<link rel="preconnect" href="https://fonts.bunny.net">
<link href="https://fonts.bunny.net/css?family=chau-philomene-one:400|inter:400,500,600,700&display=swap" rel="stylesheet">
<style>
.rg-crew{
  --brand:#22a8b8; --brand-hover:#1e97a6; --brand-mid:#167582; --brand-deep:#0e535e;
  --brand-light:#90dce4; --brand-tint:#E4F5F7;
  --ink:#111827; --ink-2:#374151; --ink-3:#6b7280;
  --line:#e5e7eb; --line-2:#d1d5db;
  --surface:#ffffff; --surface-2:#f9fafb; --surface-3:#f3f4f6;
  --ground:#EDF1F2;
  --ok:#15803d; --ok-bg:#DCF3E4;
  --warn:#9a5b06; --warn-bg:#FBEEDA;
  --crit:#a4261d; --crit-bg:#FAE6E3;
  --r:8px; --r-card:10px;
  --display:"Chau Philomene One",ui-sans-serif,system-ui,sans-serif;
  --body:"Inter",ui-sans-serif,system-ui,-apple-system,sans-serif;
  position:relative; z-index:0;
  min-height:100vh; width:100%;
  background:var(--ground); color:var(--ink);
  font-family:var(--body); font-size:16px; line-height:1.55; -webkit-font-smoothing:antialiased;
}
@media (prefers-color-scheme: dark){.rg-crew{
  --brand:#22a8b8; --brand-hover:#3fbccb; --brand-mid:#3fbccb; --brand-deep:#0b3f48;
  --brand-light:#90dce4; --brand-tint:#0E2F35;
  --ink:#F3F5F6; --ink-2:#B6C0C4; --ink-3:#8A959A;
  --line:#243237; --line-2:#32444A;
  --surface:#131E21; --surface-2:#18262A; --surface-3:#1F2F34;
  --ground:#0A1214;
  --ok:#4ec288; --ok-bg:#123024; --warn:#dda85e; --warn-bg:#31260F; --crit:#eb8279; --crit-bg:#331A18;
}}
.rg-crew *{box-sizing:border-box}
.rg-crew h1,.rg-crew h2,.rg-crew h3{font-family:var(--display); font-weight:400; margin:0; line-height:1.12; text-wrap:balance}
.rg-crew :focus-visible{outline:2px solid var(--brand); outline-offset:2px; border-radius:6px}
@media (prefers-reduced-motion:reduce){.rg-crew *{animation:none!important;transition:none!important}}

.rg-crew .appbar{position:sticky; top:0; z-index:5; display:flex; align-items:center; justify-content:space-between;
  padding:12px 18px; background:var(--surface); border-bottom:1px solid var(--line)}
.rg-crew .wordmark{font-family:var(--display); font-size:18px; letter-spacing:.01em; color:var(--ink)}
.rg-crew .wordmark span{color:var(--brand)}
.rg-crew .avatar{width:32px; height:32px; border-radius:50%; background:var(--brand); color:#fff; display:grid; place-items:center; font-size:12.5px; font-weight:700}

.rg-crew .wrap{max-width:460px; margin:0 auto; padding:20px 16px 48px; display:flex; flex-direction:column; gap:18px}

.rg-crew .greet h1{font-size:27px}
.rg-crew .greet p{color:var(--ink-2); font-size:14px; margin-top:3px}

.rg-crew .evcard{background:var(--surface); border:1px solid var(--line); border-radius:var(--r-card); overflow:hidden; box-shadow:0 1px 2px rgba(17,24,39,.04)}
.rg-crew .evhead{background:var(--brand-deep); color:#fff; padding:17px 16px 16px}
.rg-crew .evhead-top{display:flex; justify-content:space-between; gap:10px; align-items:flex-start}
.rg-crew .evhead .kicker{font-size:10.5px; font-weight:700; letter-spacing:.12em; text-transform:uppercase; opacity:.75}
.rg-crew .evhead h3{font-size:22px; margin-top:7px; letter-spacing:.01em}
.rg-crew .evhead .when{font-size:13.5px; opacity:.82; margin-top:4px; font-variant-numeric:tabular-nums}
.rg-crew .beacon{margin-top:13px; background:rgba(255,255,255,.12); border:1px solid rgba(255,255,255,.2); border-radius:var(--r); padding:11px 13px; display:flex; align-items:center; gap:12px}
.rg-crew .beacon .lbl{font-size:13.5px; opacity:.95; line-height:1.4}
.rg-crew .beacon .lbl b{display:block; font-family:var(--display); font-weight:400; font-size:18px; letter-spacing:.01em; color:var(--brand-light); margin-bottom:4px}
.rg-crew .beacon .lbl .sub{opacity:.72}

.rg-crew .evbody{padding:4px 0 0}
.rg-crew .day{display:flex; align-items:center; gap:12px; padding:12px 16px; border-bottom:1px solid var(--line)}
.rg-crew .datebox{width:46px; flex:none; text-align:center; border-radius:var(--r); background:var(--brand-tint); padding:5px 0; line-height:1.1}
.rg-crew .datebox .d{font-family:var(--display); font-size:19px; color:var(--brand-mid); font-variant-numeric:tabular-nums}
.rg-crew .datebox .m{font-size:10px; font-weight:700; text-transform:uppercase; color:var(--brand-mid); letter-spacing:.06em}
.rg-crew .day .l1{font-size:14px; font-weight:600}
.rg-crew .day .l2{font-size:12.5px; color:var(--ink-2); font-variant-numeric:tabular-nums}

.rg-crew .panels{padding:14px 16px 4px; display:flex; flex-direction:column; gap:10px}
.rg-crew .panel{background:var(--surface-2); border-radius:var(--r); padding:11px 13px}
.rg-crew .panel .h{font-size:10.5px; font-weight:700; letter-spacing:.1em; text-transform:uppercase; color:var(--ink-3)}
.rg-crew .panel .b{font-size:13.5px; margin-top:4px; line-height:1.45; white-space:pre-line}
.rg-crew .panel.attach{display:block; text-decoration:none; color:inherit; border:1px solid var(--line)}
.rg-crew .panel.attach .b{color:var(--brand-mid); font-weight:600}
.rg-crew .isnew{border:1.5px solid var(--brand-mid); box-shadow:0 0 0 3px rgba(0,90,120,.08)}
.rg-crew .newtag{display:inline-block; margin-left:6px; padding:1px 7px; border-radius:999px; background:var(--brand-mid); color:#fff; font-size:10px; font-weight:800; letter-spacing:.08em; vertical-align:2px}
.rg-crew .panel.attach:hover{border-color:var(--brand)}

.rg-crew .hint{margin:10px 16px 0; background:var(--warn-bg); border-radius:var(--r); padding:11px 13px}
.rg-crew .hint .h{font-size:10.5px; font-weight:700; letter-spacing:.1em; text-transform:uppercase; color:var(--warn)}
.rg-crew .hint .b{font-size:13.5px; margin-top:3px; color:var(--warn); line-height:1.4; white-space:pre-line}

.rg-crew .evfoot{padding:14px 16px 16px}
.rg-crew .btn-confirm{width:100%; border:none; border-radius:var(--r); padding:14px; background:var(--brand); color:#fff; font-family:var(--display); font-size:17px; letter-spacing:.01em; cursor:pointer; transition:filter .12s, transform .12s}
.rg-crew .btn-confirm:hover{filter:brightness(1.05)} .rg-crew .btn-confirm:active{transform:scale(.99)}
.rg-crew .btn-done{width:100%; text-align:center; border-radius:var(--r); padding:13px; background:var(--ok-bg); color:var(--ok); font-family:var(--display); font-size:16px; letter-spacing:.01em}
.rg-crew .evcontact{font-size:12.5px; color:var(--ink-2); text-align:center; margin-top:11px}
.rg-crew .evcontact b{color:var(--ink); font-weight:600}

.rg-crew .chip{display:inline-flex; align-items:center; gap:5px; font-size:11.5px; font-weight:700; padding:3px 10px; border-radius:999px; white-space:nowrap; flex:none}
.rg-crew .evhead .chip{background:rgba(255,255,255,.16); color:#fff}
.rg-crew .evhead .chip.ok{background:rgba(220,243,228,.22); color:#dcfce7}
.rg-crew .chip.warn{background:#fff7ed; color:#c2410c}
.rg-crew .notice{margin-top:10px; border-radius:12px; background:#fff7ed; border:1px solid #fed7aa; color:#7c2d12; padding:10px 12px; font-size:14px}
.rg-crew .l2.warn{color:#c2410c}

.rg-crew .empty{background:var(--surface); border:1px solid var(--line); border-radius:var(--r-card); padding:26px 18px; text-align:center; color:var(--ink-2); font-size:14px}

.rg-crew .seclabel{font-size:11px; font-weight:700; letter-spacing:.12em; text-transform:uppercase; color:var(--ink-3); display:flex; align-items:center; justify-content:space-between; gap:8px; width:100%; background:none; border:none; padding:6px 2px; cursor:pointer}
.rg-crew .seclabel .toggle{font-size:11px; letter-spacing:0; color:var(--ink-3)}
.rg-crew .pastlist{margin-top:8px; background:var(--surface); border:1px solid var(--line); border-radius:var(--r-card); overflow:hidden}
.rg-crew .pastrow{padding:11px 14px; border-bottom:1px solid var(--line)}
.rg-crew .pastrow:last-child{border-bottom:none}
.rg-crew .pastrow .n{font-size:14px; font-weight:600; color:var(--ink-2)}
.rg-crew .pastrow .d{margin-top:2px; display:flex; flex-wrap:wrap; gap:4px 12px; font-size:12px; color:var(--ink-3); font-variant-numeric:tabular-nums}

.rg-crew .state{background:var(--surface); border:1px solid var(--line); border-radius:var(--r-card); overflow:hidden}
.rg-crew .state .bar{height:5px; background:var(--crit)}
.rg-crew .state .inner{padding:22px 18px; text-align:center}
.rg-crew .state h1{font-size:22px; color:var(--ink)}
.rg-crew .state p{margin-top:8px; color:var(--ink-2); font-size:14px; line-height:1.5}
.rg-crew .jobrow{display:flex; align-items:center; justify-content:space-between; gap:12px; padding:12px 16px; border-bottom:1px solid var(--line)}
.rg-crew .jobrow .l1{font-size:14px; font-weight:600}
.rg-crew .jobrow .l2{font-size:12.5px; color:var(--ink-2)}
.rg-crew .jobrow .btn-confirm, .rg-crew .jobrow .btn-done{width:auto; flex:none; padding:10px 16px; font-size:15px; white-space:nowrap}
.rg-crew .badge-frei{display:inline-block; font-size:11.5px; font-weight:700; padding:2px 9px; border-radius:999px; background:var(--brand-tint); color:var(--brand-mid)}
.rg-crew .jobday{padding:10px 16px 0; font-size:11px; font-weight:700; letter-spacing:.1em; text-transform:uppercase; color:var(--ink-3)}
.rg-crew .notice-ok{margin-top:10px; border-radius:12px; background:var(--ok-bg); color:var(--ok); padding:10px 12px; font-size:14px}
</style>
</div>
