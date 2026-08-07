<?php require __DIR__ . '/_common.php'; ?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= e($businessName) ?> - Pawn Ledger</title>
<?php include('includes/links.php'); require __DIR__ . '/_style.php'; ?>
<style>
.ledger-toolbar{display:grid;grid-template-columns:minmax(280px,1.4fr) 180px 180px auto;gap:8px}
.summary-grid{display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:10px;margin-bottom:12px}
.summary-box{padding:13px;border:1px solid var(--line);border-radius:var(--radius);background:var(--card-bg)}
.summary-box span{display:block;font-size:9px;color:var(--muted);text-transform:uppercase}
.summary-box strong{display:block;margin-top:4px;font-size:18px}
.summary-box.highlight{background:var(--primary-soft)}
.ledger-info{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:8px}
.info-box{padding:11px;border:1px solid var(--line);border-radius:10px;background:var(--card-bg)}
.info-box span{display:block;font-size:9px;color:var(--muted);text-transform:uppercase}
.info-box strong{display:block;margin-top:3px;font-size:12px;word-break:break-word}
.ledger-table{font-size:10px;margin:0}
.ledger-table th{font-size:9px;text-transform:uppercase;color:var(--muted);white-space:nowrap;padding:10px}
.ledger-table td{padding:10px;vertical-align:middle}
.type-pill{display:inline-flex;padding:4px 8px;border-radius:999px;font-size:9px;font-weight:800}
.type-opening{background:#eef3ff;color:#3659a2}
.type-interest{background:#fff5df;color:#9a6700}
.type-payment{background:#eaf8f0;color:#168449}
.type-closure{background:#e9f7ef;color:#126b3d}
.type-release{background:#f2ecff;color:#6941c6}
.mini-btn{width:31px;height:31px;border:1px solid var(--line);border-radius:8px;background:var(--card-bg);display:grid;place-items:center;text-decoration:none;color:inherit}
.mini-btn:hover{background:var(--primary-soft);color:var(--primary-dark)}
.mini-btn.whatsapp{color:#168449}
.mini-btn.whatsapp:hover{background:#eaf8f0;color:#168449}
.theme-toast{position:fixed;right:18px;top:78px;z-index:20000;padding:11px 14px;border-radius:10px;color:#fff;font-size:11px;font-weight:700}
.theme-toast-success{background:#168449}.theme-toast-error{background:#c0392b}
@media(max-width:1200px){.summary-grid{grid-template-columns:repeat(3,1fr)}.ledger-info{grid-template-columns:repeat(2,1fr)}}
@media(max-width:900px){.ledger-toolbar{grid-template-columns:1fr 1fr}.ledger-toolbar .wide{grid-column:1/-1}}
@media(max-width:575px){.ledger-toolbar,.summary-grid,.ledger-info{grid-template-columns:1fr}}
@media print{
 body{background:#fff}
 .app-main{margin:0!important}
 .sidebar,.app-sidebar,nav,.navbar,.no-print,footer{display:none!important}
 .content-wrap{padding:0!important}
 .page-card{box-shadow:none!important}
}
</style>
</head>
<body>
<?php include('includes/sidebar.php'); ?>
<main class="app-main">
<?php include('includes/nav.php'); ?>
<div class="content-wrap">

<div class="page-card mb-3">
 <div class="page-head">
  <div>
   <div class="page-title">Pawn Ledger</div>
   <div class="small text-muted">Complete pawn-wise ledger with opening, interest, payments, settlement and release records.</div>
  </div>
  <div class="d-flex gap-2 flex-wrap no-print">
   <button type="button" id="printBtn" class="btn-soft"><i class="fa-solid fa-print"></i> Print</button>
   <button type="button" id="exportBtn" class="btn-soft"><i class="fa-solid fa-file-csv"></i> Export CSV</button>
  </div>
 </div>
</div>

<div class="page-card mb-3 no-print">
 <div class="card-body-x">
  <div class="ledger-toolbar">
   <select id="pawnSelect" class="form-select wide">
    <option value="">Loading pawns...</option>
   </select>
   <input type="date" id="fromDate" class="form-control">
   <input type="date" id="toDate" class="form-control">
   <button type="button" id="loadBtn" class="btn-theme"><i class="fa-solid fa-book-open"></i> Load Ledger</button>
  </div>
 </div>
</div>

<div id="ledgerWrap" class="d-none">
 <div class="summary-grid">
  <div class="summary-box"><span>Original Principal</span><strong>₹<span id="stPrincipal">0.00</span></strong></div>
  <div class="summary-box"><span>Principal Paid</span><strong>₹<span id="stPrincipalPaid">0.00</span></strong></div>
  <div class="summary-box highlight"><span>Balance Principal</span><strong>₹<span id="stBalance">0.00</span></strong></div>
  <div class="summary-box"><span>Interest Collected</span><strong>₹<span id="stInterest">0.00</span></strong></div>
  <div class="summary-box"><span>Penalty + Other</span><strong>₹<span id="stCharges">0.00</span></strong></div>
  <div class="summary-box highlight"><span>Total Collected</span><strong>₹<span id="stCollected">0.00</span></strong></div>
 </div>

 <div class="page-card mb-3">
  <div class="page-head"><div class="section-title">Pawn & Customer</div></div>
  <div class="card-body-x">
   <div class="ledger-info">
    <div class="info-box"><span>Pawn No</span><strong id="infoPawn">—</strong></div>
    <div class="info-box"><span>Customer</span><strong id="infoCustomer">—</strong></div>
    <div class="info-box"><span>Mobile</span><strong id="infoMobile">—</strong></div>
    <div class="info-box"><span>Category</span><strong id="infoCategory">—</strong></div>
    <div class="info-box"><span>Pawn Date</span><strong id="infoPawnDate">—</strong></div>
    <div class="info-box"><span>Due Date</span><strong id="infoDueDate">—</strong></div>
    <div class="info-box"><span>Interest Rule</span><strong id="infoInterest">—</strong></div>
    <div class="info-box"><span>Status</span><strong id="infoStatus">—</strong></div>
   </div>
  </div>
 </div>

 <div class="page-card">
  <div class="page-head">
   <div>
    <div class="section-title">Ledger Transactions</div>
    <div class="small text-muted" id="ledgerPeriod">All records</div>
   </div>
  </div>
  <div class="table-responsive">
   <table class="table ledger-table align-middle">
    <thead>
     <tr>
      <th>Date</th>
      <th>Type</th>
      <th>Reference</th>
      <th>Description</th>
      <th>Principal</th>
      <th>Interest</th>
      <th>Penalty</th>
      <th>Other</th>
      <th>Total</th>
      <th>Balance</th>
      <th>Payment</th>
      <th class="text-end no-print">Action</th>
     </tr>
    </thead>
    <tbody id="ledgerBody"></tbody>
   </table>
  </div>
  <div id="emptyState" class="text-center text-muted p-5 d-none">No ledger records found for the selected period.</div>
 </div>
</div>

<?php include('includes/footer.php'); ?>
</div>
</main>
<?php include('includes/script.php'); ?>
<script src="assets/js/script.js"></script>
<script>
(()=>{
'use strict';

const api='api/pawn-ledger.php';
const csrf=<?= json_encode($csrfToken) ?>;
const $=id=>document.getElementById(id);
let data={pawns:[]}, ledgerRows=[], currentPawn=null;

function esc(v){return String(v??'').replace(/[&<>'"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#039;','"':'&quot;'}[c]))}
function money(v){return Number(v||0).toLocaleString('en-IN',{minimumFractionDigits:2,maximumFractionDigits:2})}
function dateView(v){if(!v)return '—';const p=String(v).split('-');return p.length===3?`${p[2]}-${p[1]}-${p[0]}`:v}
function note(t,m){const x=document.createElement('div');x.className='theme-toast theme-toast-'+(t==='ok'?'success':'error');x.textContent=m;document.body.appendChild(x);setTimeout(()=>x.remove(),3500)}

async function req(payload){
 const f=new FormData();
 Object.entries(payload).forEach(([k,v])=>f.append(k,v??''));
 f.append('csrf_token',csrf);
 const r=await fetch(api,{method:'POST',body:f,credentials:'same-origin',headers:{Accept:'application/json'}});
 const raw=await r.text();
 let j;
 try{j=JSON.parse(raw)}catch(e){throw new Error(raw.replace(/<[^>]*>/g,' ').slice(0,320))}
 if(!r.ok||!j.success)throw new Error(j.message||'Request failed');
 return j;
}

function whatsappNumber(v){
 let n=String(v||'').replace(/\D+/g,'');
 if(n.length===10)n='91'+n;
 else if(n.length===11&&n.startsWith('0'))n='91'+n.slice(1);
 return n;
}

function absoluteUrl(path){
 const current=new URL(window.location.href);
 const dir=current.pathname.substring(0,current.pathname.lastIndexOf('/')+1);
 return current.origin+dir+path.replace(/^\/+/,'');
}

function receiptFor(row){
 if(row.type==='Interest'&&row.record_id){
  return 'pawn-interest-receipt.php?collection_id='+encodeURIComponent(row.record_id)+'&receipt='+encodeURIComponent(row.reference||'');
 }
 if((row.type==='Payment'||row.type==='Closure')&&row.record_id){
  return 'pawn-payment-receipt.php?payment_id='+encodeURIComponent(row.record_id)+'&receipt='+encodeURIComponent(row.reference||'');
 }
 if(row.type==='Release'&&currentPawn){
  return 'pawn-closed-receipt.php?id='+encodeURIComponent(currentPawn.id)+'&ref='+encodeURIComponent(currentPawn.pawn_no||'');
 }
 return '';
}

function whatsappFor(row){
 const receipt=receiptFor(row);
 if(!receipt||!currentPawn)return '';
 const mobile=whatsappNumber(currentPawn.mobile);
 if(!mobile)return '';

 const msg=
  'Dear '+(currentPawn.customer_name||'Customer')+',\n\n'
  +'Pawn ledger receipt details:\n'
  +'Pawn No: '+(currentPawn.pawn_no||'')+'\n'
  +'Type: '+(row.type||'')+'\n'
  +'Reference: '+(row.reference||'')+'\n'
  +'Total: ₹'+money(row.total_amount||0)+'\n'
  +'Balance Principal: ₹'+money(row.balance_after||0)+'\n\n'
  +'View receipt:\n'+absoluteUrl(receipt)+'\n\nThank you.';

 return 'https://wa.me/'+mobile+'?text='+encodeURIComponent(msg);
}

function typeBadge(type){
 const cls={
  'Opening':'type-opening',
  'Interest':'type-interest',
  'Payment':'type-payment',
  'Closure':'type-closure',
  'Release':'type-release'
 }[type]||'type-opening';
 return `<span class="type-pill ${cls}">${esc(type)}</span>`;
}

function renderLedger(){
 $('ledgerBody').innerHTML=ledgerRows.map(r=>{
  const receipt=receiptFor(r);
  const wa=whatsappFor(r);
  return `<tr>
   <td>${dateView(r.record_date)}</td>
   <td>${typeBadge(r.type)}</td>
   <td><strong>${esc(r.reference||'—')}</strong></td>
   <td>${esc(r.description||'—')}</td>
   <td>₹${money(r.principal_amount)}</td>
   <td>₹${money(r.interest_amount)}</td>
   <td>₹${money(r.penalty_amount)}</td>
   <td>₹${money(r.other_charges)}</td>
   <td><strong>₹${money(r.total_amount)}</strong></td>
   <td><strong>₹${money(r.balance_after)}</strong></td>
   <td>${esc(r.payment_method||'—')}<div class="text-muted">${esc(r.payment_reference||'')}</div></td>
   <td class="no-print">
    <div class="d-flex justify-content-end gap-1">
     ${receipt?`<a class="mini-btn" href="${receipt}" target="_blank" rel="noopener" title="Receipt"><i class="fa-solid fa-receipt"></i></a>`:''}
     ${wa?`<a class="mini-btn whatsapp" href="${wa}" target="_blank" rel="noopener" title="WhatsApp"><i class="fa-brands fa-whatsapp"></i></a>`:''}
    </div>
   </td>
  </tr>`;
 }).join('');

 $('emptyState').classList.toggle('d-none',ledgerRows.length>0);
}

function fillSummary(j){
 const p=j.pawn||{};
 const s=j.summary||{};
 currentPawn=p;

 $('infoPawn').textContent=p.pawn_no||'—';
 $('infoCustomer').textContent=(p.customer_name||'—')+(p.customer_code?' · '+p.customer_code:'');
 $('infoMobile').textContent=p.mobile||'—';
 $('infoCategory').textContent=p.category_name||'—';
 $('infoPawnDate').textContent=dateView(p.pawn_date);
 $('infoDueDate').textContent=p.due_date?dateView(p.due_date):'At Closure';
 $('infoInterest').textContent=Number(p.interest_percent||0).toFixed(3)+'% '+(p.interest_period||'')+' · '+(p.interest_method||'');
 $('infoStatus').textContent=p.status||'—';

 $('stPrincipal').textContent=money(s.original_principal);
 $('stPrincipalPaid').textContent=money(s.principal_paid);
 $('stBalance').textContent=money(s.balance_principal);
 $('stInterest').textContent=money(s.interest_collected);
 $('stCharges').textContent=money(Number(s.penalty_collected||0)+Number(s.other_charges_collected||0));
 $('stCollected').textContent=money(s.total_collected);

 const f=$('fromDate').value,t=$('toDate').value;
 $('ledgerPeriod').textContent=(f||t)?((f?dateView(f):'Beginning')+' to '+(t?dateView(t):'Today')):'All records';
}

async function loadOptions(){
 try{
  const j=await req({action:'options'});
  data=j;
  $('pawnSelect').innerHTML='<option value="">Select pawn</option>'+(j.pawns||[]).map(p=>
   `<option value="${p.id}">${esc(p.pawn_no)} · ${esc(p.customer_name||'')} · ${esc(p.status||'')} · ₹${money(p.balance_principal)}</option>`
  ).join('');
 }catch(e){note('bad',e.message)}
}

async function loadLedger(){
 const pawnId=$('pawnSelect').value;
 if(!pawnId)return note('bad','Select a pawn.');
 try{
  const j=await req({
   action:'ledger',
   pawn_id:pawnId,
   from_date:$('fromDate').value,
   to_date:$('toDate').value
  });
  ledgerRows=j.rows||[];
  fillSummary(j);
  renderLedger();
  $('ledgerWrap').classList.remove('d-none');
 }catch(e){note('bad',e.message)}
}

function exportCsv(){
 if(!ledgerRows.length)return note('bad','No ledger records available to export.');
 const headers=['Date','Type','Reference','Description','Principal','Interest','Penalty','Other Charges','Total','Balance After','Payment Method','Payment Reference'];
 const values=ledgerRows.map(r=>[
  r.record_date,r.type,r.reference,r.description,r.principal_amount,r.interest_amount,
  r.penalty_amount,r.other_charges,r.total_amount,r.balance_after,r.payment_method,r.payment_reference
 ]);
 const csv=[headers,...values].map(row=>row.map(v=>`"${String(v??'').replace(/"/g,'""')}"`).join(',')).join('\r\n');
 const blob=new Blob(['\ufeff'+csv],{type:'text/csv;charset=utf-8;'});
 const a=document.createElement('a');
 a.href=URL.createObjectURL(blob);
 a.download='pawn-ledger-'+(currentPawn?.pawn_no||'export')+'.csv';
 a.click();
 URL.revokeObjectURL(a.href);
}

$('loadBtn').onclick=loadLedger;
$('pawnSelect').onchange=loadLedger;
$('fromDate').onchange=()=>{if($('pawnSelect').value)loadLedger()};
$('toDate').onchange=()=>{if($('pawnSelect').value)loadLedger()};
$('printBtn').onclick=()=>window.print();
$('exportBtn').onclick=exportCsv;

loadOptions();
})();
</script>
</body>
</html>
