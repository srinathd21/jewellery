<style>
:root{
    --primary:<?=pawn_e($theme['primary_color'])?>;
    --primary-dark:<?=pawn_e($theme['primary_dark_color'])?>;
    --primary-soft:<?=pawn_e($theme['primary_soft_color'])?>;
    --sidebar-gradient-1:<?=pawn_e($theme['sidebar_gradient_1'])?>;
    --sidebar-gradient-2:<?=pawn_e($theme['sidebar_gradient_2'])?>;
    --sidebar-gradient-3:<?=pawn_e($theme['sidebar_gradient_3'])?>;
    --page-bg:<?=pawn_e($theme['page_background'])?>;
    --card-bg:<?=pawn_e($theme['card_background'])?>;
    --text-color:<?=pawn_e($theme['text_color'])?>;
    --muted-color:<?=pawn_e($theme['muted_text_color'])?>;
    --border-color:<?=pawn_e($theme['border_color'])?>;
    --sidebar-width:<?=(int)$theme['sidebar_width_px']?>px;
    --radius:<?=(int)$theme['border_radius_px']?>px;
}
body{
    background:var(--page-bg);
    color:var(--text-color);
    font-family:<?=json_encode((string)$theme['font_family'])?>,sans-serif;
    font-size:11px;
}
.sidebar{
    background:linear-gradient(180deg,var(--sidebar-gradient-1),var(--sidebar-gradient-2),var(--sidebar-gradient-3))!important;
}
.page-head{
    display:flex;align-items:center;justify-content:space-between;gap:12px;
    margin-bottom:12px;flex-wrap:wrap;
}
.page-title{
    margin:0;
    color:var(--text-color);
    font:700 20px <?=json_encode((string)$theme['heading_font_family'])?>,serif;
}
.page-subtitle{margin:3px 0 0;color:var(--muted-color);font-size:10px}
.stat-grid{
    display:grid;grid-template-columns:repeat(4,minmax(0,1fr));
    gap:10px;margin-bottom:12px;
}
.stat-card,.ui-card{
    background:var(--card-bg);
    border:1px solid var(--border-color);
    border-radius:var(--radius);
}
.stat-card{
    min-height:84px;padding:13px 15px;display:flex;align-items:center;gap:12px;
}
.stat-icon{
    width:44px;height:44px;flex:0 0 44px;display:grid;place-items:center;
    border-radius:calc(var(--radius)*.8);background:var(--primary-soft);color:var(--primary-dark);
    font-size:15px;
}
.stat-label{font-size:10px;color:var(--muted-color)}
.stat-value{margin-top:2px;font-size:23px;line-height:1.1;font-weight:800}
.ui-card{overflow:hidden;margin-bottom:12px}
.ui-card-head{
    min-height:50px;padding:12px 14px;border-bottom:1px solid var(--border-color);
    display:flex;align-items:center;justify-content:space-between;gap:10px;
}
.ui-card-title{
    color:var(--text-color);
    font:700 15px <?=json_encode((string)$theme['heading_font_family'])?>,serif;
}
.ui-card-body{padding:14px}
.form-grid{display:grid;grid-template-columns:repeat(12,minmax(0,1fr));gap:12px}
.col-12{grid-column:span 12}.col-8{grid-column:span 8}.col-6{grid-column:span 6}.col-4{grid-column:span 4}.col-3{grid-column:span 3}.col-2{grid-column:span 2}
.form-label,.field-label{
    display:block;font-size:10px;font-weight:700;margin-bottom:5px;color:var(--text-color)
}
.required:after{content:" *";color:#c0392b}
.form-control,.form-select{
    width:100%;min-height:38px;border:1px solid var(--border-color);border-radius:9px;
    background:var(--card-bg);color:var(--text-color);font-size:11px;box-shadow:none;
}
.form-control:focus,.form-select:focus{
    border-color:var(--primary);
    box-shadow:0 0 0 .2rem color-mix(in srgb,var(--primary) 12%,transparent);
}
textarea.form-control{min-height:90px;resize:vertical}
.help{margin-top:4px;color:var(--muted-color);font-size:9px}
.filter-row{
    display:grid;grid-template-columns:minmax(220px,1.7fr) repeat(3,minmax(130px,.9fr)) auto;
    gap:8px;align-items:end;width:100%;
}
.filter-item{min-width:0}
.filter-actions{display:flex;gap:6px}
.btn-primary-theme,.btn-theme{
    min-height:39px;border:0;border-radius:10px;padding:9px 14px;
    display:inline-flex;align-items:center;justify-content:center;gap:7px;
    color:#fff!important;background:linear-gradient(135deg,var(--primary),var(--primary-dark));
    font-size:11px;font-weight:700;text-decoration:none!important;
}
.btn-primary-theme:hover,.btn-theme:hover{filter:brightness(1.03);color:#fff}
.btn-light,.btn-outline-primary,.btn-outline-secondary,.btn-outline-info,.btn-outline-danger{
    font-size:10px;border-radius:8px;
}
.table-responsive{overflow:auto}
.ui-table{
    width:100%;margin:0;border-collapse:collapse;font-size:10px;
}
.ui-table th{
    padding:10px 12px;border-bottom:1px solid var(--border-color);
    background:color-mix(in srgb,var(--muted-color) 6%,transparent);
    color:var(--muted-color);font-size:9px;text-transform:uppercase;letter-spacing:.04em;white-space:nowrap;
}
.ui-table td{
    padding:10px 12px;vertical-align:middle;border-bottom:1px solid var(--border-color);
    background:var(--card-bg)!important;color:var(--text-color);white-space:nowrap;
}
.avatar{
    width:40px;height:40px;flex:0 0 40px;border-radius:9px;
    display:grid;place-items:center;background:var(--primary-soft);color:var(--primary-dark);font-weight:800;
}
.badge-soft{
    display:inline-flex;align-items:center;border-radius:999px;padding:4px 8px;font-size:9px;font-weight:700;
}
.badge-success{background:#eaf8f0;color:#168449}
.badge-warning{background:#fff4d8;color:#9a6800}
.badge-danger{background:#fdecec;color:#bd2d2d}
.badge-info{background:#e8f1ff;color:#275db3}
.badge-muted{background:color-mix(in srgb,var(--muted-color) 12%,transparent);color:var(--muted-color)}
.actions{display:inline-flex;gap:4px;align-items:center}
.actions form{margin:0}
.action-btn,.actions .btn{
    width:30px;height:30px;min-height:30px;padding:0;
    display:inline-grid;place-items:center;border:1px solid var(--border-color);
    border-radius:8px;background:var(--card-bg);color:var(--text-color);font-size:10px;
}
.action-btn:hover,.actions .btn:hover{background:var(--primary-soft);color:var(--primary-dark)}
.empty-state{padding:50px 20px;text-align:center;color:var(--muted-color);font-size:11px}
.pagination-wrap{
    min-height:56px;padding:10px 12px;border-top:1px solid var(--border-color);
    display:flex;align-items:center;justify-content:space-between;gap:12px;
}
.pagination{margin:0;gap:4px}
.pagination .page-link{
    min-width:32px;height:32px;padding:0 9px;border:1px solid var(--border-color);
    border-radius:8px!important;background:var(--card-bg);color:var(--text-color);
    font-size:10px;display:grid;place-items:center;box-shadow:none;
}
.pagination .page-item.active .page-link{
    border-color:var(--primary);background:linear-gradient(135deg,var(--primary),var(--primary-dark));color:#fff;
}
.alert-box{padding:10px 12px;margin-bottom:12px;border-radius:9px;font-size:10px;font-weight:600}
.alert-success-box{background:#eaf8f0;color:#168449;border:1px solid #bfe8ce}
.alert-danger-box{background:#fdecec;color:#bd2d2d;border:1px solid #f4caca}
.profile-grid{display:grid;grid-template-columns:165px 1fr;gap:18px;align-items:start}
.info-list{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px}
.info-item{padding:10px 12px;border:1px solid var(--border-color);border-radius:9px;background:var(--card-bg)}
.info-key{font-size:9px;color:var(--muted-color);text-transform:uppercase;letter-spacing:.03em}
.info-value{margin-top:3px;font-size:11px;font-weight:700;white-space:normal}
.doc-preview{width:120px;height:120px;object-fit:cover;border-radius:10px;border:1px solid var(--border-color)}
.form-check-label{font-size:10px}
.theme-toast{
    position:fixed;right:18px;top:78px;z-index:20000;min-width:260px;max-width:420px;
    padding:11px 14px;border-radius:10px;color:#fff;font-size:11px;font-weight:600;
    box-shadow:0 14px 35px rgba(0,0,0,.22);opacity:0;transform:translateY(-10px);transition:.22s;
}
.theme-toast.show{opacity:1;transform:translateY(0)}
.theme-toast-success{background:#168449}.theme-toast-error{background:#c0392b}
body.dark-mode,body[data-theme="dark"],html.dark-mode body,html[data-theme="dark"] body{
    --page-bg:#0f151b;--card-bg:#182129;--text-color:#f3f6f8;--muted-color:#9aa7b3;--border-color:#2c3944;
}
@media(max-width:991.98px){
    .stat-grid{grid-template-columns:repeat(2,minmax(0,1fr))}
    .filter-row{grid-template-columns:1fr 1fr}
    .filter-row .filter-item:first-child{grid-column:1/-1}
    .col-8,.col-6,.col-4,.col-3,.col-2{grid-column:span 12}
    .profile-grid{grid-template-columns:1fr}
    .info-list{grid-template-columns:1fr}
    .ui-card.table-card{overflow:visible;border:0;background:transparent}
    .ui-table.mobile-cards,.ui-table.mobile-cards tbody{display:block}
    .ui-table.mobile-cards thead{display:none}
    .ui-table.mobile-cards tbody{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}
    .ui-table.mobile-cards tbody tr{display:grid;grid-template-columns:1fr 1fr;padding:14px;border:1px solid var(--border-color);border-radius:var(--radius);background:var(--card-bg)}
    .ui-table.mobile-cards tbody td{display:flex;justify-content:space-between;gap:10px;padding:8px 0;border:0;border-bottom:1px dashed var(--border-color);white-space:normal;text-align:right}
    .ui-table.mobile-cards tbody td::before{content:attr(data-label);color:var(--muted-color);font-size:9px;font-weight:700;text-transform:uppercase;text-align:left}
    .ui-table.mobile-cards tbody td.main-cell,.ui-table.mobile-cards tbody td.actions-cell{grid-column:1/-1}
    .ui-table.mobile-cards tbody td.main-cell::before{display:none}
    .ui-table.mobile-cards tbody td.actions-cell{border-bottom:0}
}
@media(max-width:767.98px){
    .content-wrap{padding-left:10px;padding-right:10px}
    .stat-grid,.filter-row{grid-template-columns:1fr}
    .filter-row .filter-item:first-child{grid-column:auto}
    .ui-table.mobile-cards tbody{grid-template-columns:1fr}
    .pagination-wrap{align-items:flex-start;flex-direction:column}
}
</style>