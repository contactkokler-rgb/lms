<?php
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/QuoteInvoiceService.php';
require_once __DIR__ . '/config/ClientProjectService.php';
if (!class_exists('QuoteInvoiceService')) {
    require_once dirname(__FILE__) . '/config/QuoteInvoiceService.php';
}
if (!class_exists('ClientProjectService')) {
    require_once dirname(__FILE__) . '/config/ClientProjectService.php';
}

$user = require_auth();
$account_id = (int)($user['account_id'] ?? 0);
if (!$account_id) { header('Location: ' . APP_URL . '/index.php'); exit; }
QuoteInvoiceService::initSchema();
ClientProjectService::initSchema();
if (function_exists('can') && !can('quotes', 'create') && !can('quotes', 'edit')) { http_response_code(403); include __DIR__ . '/layouts/403.php'; exit; }

$page_title = 'Quote Generator';
$active_nav = 'billing';
$company = QuoteInvoiceService::getCompanyProfile($account_id);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) { http_response_code(403); die('CSRF'); }
    $act = $_POST['action'] ?? '';
    if ($act === 'create_quote') {
        $payload = $_POST;
        $items = QuoteInvoiceService::normalizeItemsFromPost($payload);
        if (!$items) {
            $_SESSION['flash'] = ['type'=>'error','msg'=>'Add at least one line item.'];
        } else {
            if (trim((string)($payload['ai_content'] ?? '')) === '') {
                $servicesSelected = [];
                foreach ($items as $it) {
                    $servicesSelected[] = [
                        'name' => (string)($it['item_name'] ?? ''),
                        'description' => (string)($it['item_desc'] ?? ''),
                        'price' => (float)($it['unit_price'] ?? 0),
                        'features' => (string)($it['item_desc'] ?? ''),
                    ];
                }
                $ctx = [
                    'services_selected' => $servicesSelected,
                    'user_company' => [
                        'name' => (string)($company['company_name'] ?? 'Your Company'),
                        'description' => (string)($company['industry'] ?? ''),
                        'services' => (string)($company['services_list'] ?? ''),
                        'tone' => 'professional',
                    ],
                    'client_company' => [
                        'name' => (string)($payload['client_company'] ?? $payload['client_name'] ?? 'Client Company'),
                        'industry' => (string)($company['industry'] ?? 'general'),
                        'requirements_summary' => (string)($payload['client_profile'] ?? ''),
                    ],
                    'optional_user_notes' => (string)($payload['notes'] ?? ''),
                ];
                $ai = QuoteInvoiceService::aiSuggestItems(
                    (string)($payload['service_description'] ?? ''),
                    (string)($company['industry'] ?? 'general'),
                    (string)($payload['client_profile'] ?? ''),
                    (string)($company['services_list'] ?? ''),
                    $ctx
                );
                $payload['ai_prompt'] = 'SYSTEM ROLE: Professional business proposal generator engine inside SaaS LMS';
                $payload['ai_content'] = (string)($ai['detailed_quote'] ?? '');
            }
            $id = QuoteInvoiceService::createDocument($account_id, (int)$user['id'], 'quote', $payload, $items);
            $_SESSION['flash'] = ['type'=>'success','msg'=>'Quote created (#' . $id . ').'];
        }
        header('Location: ' . APP_URL . '/quote_generator.php'); exit;
    }

    if ($act === 'ai_suggest') {
        header('Content-Type: application/json');
        $services_selected = json_decode((string)($_POST['services_selected_json'] ?? '[]'), true);
        if (!is_array($services_selected)) $services_selected = [];
        $context = [
            'services_selected' => $services_selected,
            'user_company' => [
                'name' => (string)($_POST['user_company_name'] ?? 'Your Company'),
                'description' => (string)($_POST['user_company_description'] ?? ''),
                'services' => (string)($_POST['user_company_services'] ?? ''),
                'tone' => (string)($_POST['user_company_tone'] ?? 'professional'),
            ],
            'client_company' => [
                'name' => (string)($_POST['client_company_name'] ?? 'Client Company'),
                'industry' => (string)($_POST['client_industry'] ?? $company['industry'] ?? 'general'),
                'requirements_summary' => (string)($_POST['client_profile'] ?? ''),
            ],
            'optional_user_notes' => (string)($_POST['optional_user_notes'] ?? ''),
        ];
        $suggestion = QuoteInvoiceService::aiSuggestItems(
            (string)($_POST['service_description'] ?? ''),
            (string)($_POST['industry'] ?? 'general'),
            (string)($_POST['client_profile'] ?? ''),
            (string)($_POST['services_context'] ?? ''),
            $context
        );
        echo json_encode($suggestion);
        exit;
    }
}

$services = QuoteInvoiceService::getServices($account_id);
$clients = db_fetch_all('SELECT id, name, email, phone, company, notes FROM clients WHERE account_id=? ORDER BY name ASC', 'i', $account_id);
$leads = db_fetch_all('SELECT id FROM leads WHERE account_id=? ORDER BY id DESC LIMIT 300', 'i', $account_id);
$quote_templates = QuoteInvoiceService::getTemplates($account_id, 'quote');
$flash = $_SESSION['flash'] ?? null; unset($_SESSION['flash']);

include __DIR__ . '/layouts/header.php';
?>
<?php if ($flash): ?><div class="alert alert-<?= h($flash['type']) ?> mb-4" data-auto-dismiss><?= $flash['msg'] ?></div><?php endif; ?>
<div class="page-header"><div><h1 class="page-title">Quote Generator</h1><p class="page-subtitle">Build detailed quotations with client auto-fill, service catalog and AI assistance.</p></div></div>

<div class="card"><div class="card-body">
<form method="POST" id="quoteForm">
  <?= csrf_field() ?><input type="hidden" name="action" value="create_quote">
  <div class="grid grid-cols-3 gap-2 mb-2">
    <input class="input input-sm" name="title" placeholder="Quote title" required>
    <select class="select select-sm" name="template_id"><option value="">Default Quote Template</option><?php foreach ($quote_templates as $tpl): ?><option value="<?= (int)$tpl['id'] ?>"><?= h($tpl['template_name']) ?></option><?php endforeach; ?></select>
    <input class="input input-sm" type="date" name="valid_until" placeholder="Valid Until">

    <select class="select select-sm" id="clientSelect"><option value="">Select client</option><?php foreach ($clients as $cl): ?><option value="<?= (int)$cl['id'] ?>"><?= h($cl['name']) ?></option><?php endforeach; ?></select>
    <input class="input input-sm" name="client_name" id="clientName" placeholder="Client Name" required>
    <input class="input input-sm" name="client_email" id="clientEmail" placeholder="Client Email">

    <input class="input input-sm" name="client_phone" id="clientPhone" placeholder="Client Phone">
    <input class="input input-sm" name="client_company" id="clientCompany" placeholder="Client Company">
    <input class="input input-sm" name="client_gstin" id="clientGstin" placeholder="Client GSTIN">

    <select class="select select-sm" name="lead_id"><option value="">Select lead id</option><?php foreach ($leads as $ld): ?><option value="<?= (int)$ld['id'] ?>">Lead #<?= (int)$ld['id'] ?></option><?php endforeach; ?></select>
    <input class="input input-sm" name="currency" value="<?= h($company['base_currency'] ?? 'INR') ?>" placeholder="Currency">
    <input class="input input-sm" type="color" name="doc_primary_color" value="<?= h($company['doc_primary_color'] ?? '#1d4ed8') ?>">
  </div>

  <textarea class="input input-sm mb-2" name="client_profile" id="clientProfile" placeholder="Client profile / business summary for AI + quote narrative" style="min-height:70px;"></textarea>
  <textarea class="input input-sm mb-2" name="service_description" placeholder="Prompt for AI quote generation: describe scope and expected deliverables" style="min-height:70px;">
ROLE:
You are an expert business proposal writer embedded inside a SaaS system.

OBJECTIVE:
Generate a complete, professional quotation document in HTML format.

INPUT:
- Services: {{services_selected}}
- Pricing: {{pricing_summary}}
- Sender Company: {{user_company}}
- Client Company: {{client_company}}
- Notes: {{optional_notes}}

INSTRUCTIONS:
1. Analyze client_company to infer:
   - business type (startup / SME / enterprise / ecommerce / local business)
   - communication style (formal / friendly / premium)

2. Adapt tone accordingly:
   - Enterprise → formal, structured, precise
   - Startup → modern, slightly conversational
   - Local business → simple, clear, benefit-focused

3. Expand all services into detailed scope with deliverables.

4. If notes exist → incorporate naturally.

5. Fill missing info using industry-standard assumptions.

STRUCTURE:
- Cover Letter
- Project Overview
- Scope of Work (service-wise breakdown)
- Features Included
- Deliverables
- Timeline
- Pricing Breakdown
- Payment Terms
- Optional Add-ons
- Support & Maintenance
- Assumptions
- Closing

OUTPUT RULES:
- Return ONLY HTML
- Use semantic tags (<h1>, <h2>, <p>, <table>, <ul>)
- Clean inline styling (minimal, professional)
- No markdown
- No explanations
    </textarea>
  <div id="promptSuggestions" class="text-xs text-muted mb-2"></div>
  <input type="hidden" name="ai_prompt" id="aiPromptField">
  <textarea class="input input-sm mb-2" name="ai_content" id="aiContentField" placeholder="AI generated detailed quote content" style="min-height:120px;"></textarea>
  <textarea class="input input-sm mb-2" name="notes" placeholder="Additional quote notes / terms" style="min-height:70px;"></textarea>

  <div id="itemsWrap" style="border:1px dashed var(--border);padding:8px;border-radius:8px;margin-bottom:8px;">
    <div class="text-xs text-muted mb-2">Services Line Items</div>
  </div>
  <button type="button" class="btn btn-xs btn-secondary" onclick="addServiceRow()">+ Add More Service</button>

  <div class="grid grid-cols-2 gap-2 mt-2 mb-2">
    <button class="btn btn-sm btn-secondary" type="button" onclick="aiSuggest(this.form)">AI Detailed Quote</button>
    <div class="text-xs text-muted ai-hint"></div>
  </div>

  <div class="grid grid-cols-3 gap-2 mb-2">
    <label><input type="checkbox" name="approval_required" value="1"> Approval required</label>
    <label><input type="checkbox" name="is_recurring" value="1"> Recurring</label>
    <input class="input input-sm" name="recurring_interval" placeholder="Interval e.g. monthly">
  </div>

  <button class="btn btn-primary btn-sm">Create Quote</button>
</form>
</div></div>

<script>
const clients = <?= json_encode($clients, JSON_UNESCAPED_UNICODE) ?>;
const services = <?= json_encode($services, JSON_UNESCAPED_UNICODE) ?>;

function addServiceRow(pref = null){
  const wrap = document.getElementById('itemsWrap');
  const row = document.createElement('div');
  row.className = 'grid grid-cols-7 gap-1 mb-1';
  const opts = ['<option value="">Select service</option>'].concat(services.map(s=>`<option value="${s.id}">${(s.service_name||'').replace(/</g,'&lt;')}</option>`)).join('');
  row.innerHTML = `
    <select class="select select-sm" name="service_id[]" onchange="onServicePick(this)">${opts}</select>
    <input class="input input-sm" name="item_name[]" placeholder="Service">
    <input class="input input-sm" name="item_desc[]" placeholder="Description">
    <input class="input input-sm" name="quantity[]" type="number" step="0.01" value="1">
    <input class="input input-sm" name="unit_price[]" type="number" step="0.01" placeholder="Price">
    <input class="input input-sm" name="tax_rate[]" type="number" step="0.01" value="18" placeholder="Tax %">
    <button class="btn btn-xs btn-danger" type="button" onclick="removeServiceRow(this)">Delete</button>`;
  wrap.appendChild(row);
  if (pref) {
    const select = row.querySelector('select[name="service_id[]"]');
    select.value = String(pref.id || '');
    onServicePick(select);
  }
}
function removeServiceRow(btn){
  const wrap = document.getElementById('itemsWrap');
  const rows = wrap.querySelectorAll('.grid');
  if (rows.length <= 1) return;
  btn.closest('.grid').remove();
}

function renderPromptSuggestions(client = null){
  const holder = document.getElementById('promptSuggestions');
  const clientName = client && client.name ? client.name : 'this client';
  const company = client && client.company ? client.company : 'their organization';
  const notes = client && client.notes ? client.notes : 'the available profile details';
  const prompts = [
    `ROLE:
You are an expert business proposal writer embedded inside a SaaS system.

OBJECTIVE:
Generate a complete, professional quotation document in HTML format.

INPUT:
- Services: {{services_selected}}
- Pricing: {{pricing_summary}}
- Sender Company: {{user_company}}
- Client Company: {{client_company}}
- Notes: {{optional_notes}}

INSTRUCTIONS:
1. Analyze client_company to infer:
   - business type (startup / SME / enterprise / ecommerce / local business)
   - communication style (formal / friendly / premium)

2. Adapt tone accordingly:
   - Enterprise → formal, structured, precise
   - Startup → modern, slightly conversational
   - Local business → simple, clear, benefit-focused

3. Expand all services into detailed scope with deliverables.

4. If notes exist → incorporate naturally.

5. Fill missing info using industry-standard assumptions.

STRUCTURE:
- Cover Letter
- Project Overview
- Scope of Work (service-wise breakdown)
- Features Included
- Deliverables
- Timeline
- Pricing Breakdown
- Payment Terms
- Optional Add-ons
- Support & Maintenance
- Assumptions
- Closing

OUTPUT RULES:
- Return ONLY HTML
- Use semantic tags (<h1>, <h2>, <p>, <table>, <ul>)
- Clean inline styling (minimal, professional)
- No markdown
- No explanations`,
    `ROLE:
You are a premium digital agency proposal specialist.

OBJECTIVE:
Generate a high-end, persuasive quotation document in HTML.

INPUT:
{{all_data}}

INSTRUCTIONS:
1. Assume client expects premium quality.
2. Use confident, polished, conversion-focused language.
3. Emphasize value, ROI, scalability—not just features.
4. Expand each service into outcomes and business impact.
5. Maintain structured, elegant flow.

CLIENT ADAPTATION:
- If client appears corporate → increase formality
- If brand-focused → emphasize design & UX
- If growth-focused → emphasize performance & scalability

STRUCTURE:
- Personalized Introduction
- Understanding of Client Needs
- Strategic Approach
- Detailed Scope
- Key Features & Benefits
- Deliverables
- Timeline (phase-wise)
- Investment Summary (table)
- Payment Terms
- Value Add / Upsell Opportunities
- Support Model
- Assumptions
- Strong Closing Statement

OUTPUT:
- Fully formatted HTML document
- Use tables for pricing
- Use sections with clear spacing
- No text outside HTML`,
    `ROLE:
You are a business consultant creating easy-to-understand quotations.

OBJECTIVE:
Generate a simple, clear quotation in HTML for non-technical clients.

INPUT:
{{all_data}}

INSTRUCTIONS:
1. Assume client is not technical.
2. Use simple language (avoid jargon).
3. Focus on:
   - what is being done
   - how it helps the client
4. Keep explanations short but complete.
5. Automatically organize services into clear sections.

STRUCTURE:
- Greeting
- What We Will Do (scope)
- Features Included
- Timeline
- Cost Breakdown
- Payment Terms
- Support
- Notes / Assumptions
- Closing

OUTPUT:
- Clean HTML
- Use bullet points for clarity
- Minimal styling
- No markdown or explanations`,
    `ROLE:
You are an AI-powered proposal engine that personalizes quotations based on both sender and client context.

OBJECTIVE:
Generate a highly relevant, personalized quotation in HTML.

INPUT:
{{all_data}}

INTELLIGENCE LAYER:
1. Analyze:
   - client industry
   - project type
   - services selected

2. Infer:
   - key priorities (design / performance / leads / ecommerce / branding)
   - expected tone (formal / modern / direct)

3. Personalize:
   - opening paragraph referencing client business
   - tailor benefits per service
   - suggest relevant add-ons automatically

GENERATION RULES:
- Do not ask for more input
- Do not leave placeholders
- Expand into full professional document

STRUCTURE:
- Personalized Opening
- Project Understanding
- Scope of Work (detailed per service)
- Features & Benefits
- Deliverables
- Timeline
- Pricing Table
- Payment Terms
- Recommended Add-ons
- Support
- Assumptions
- Closing

OUTPUT:
- Valid HTML document
- Structured and readable
- Ready for direct rendering or PDF export
- No extra commentary`
  ];
  holder.innerHTML = prompts.map((p, i) =>
    `<div style=\"margin-bottom:6px;\"><button type=\"button\" class=\"btn btn-xs btn-secondary\" onclick=\"usePromptSuggestion(${i})\">Use Prompt ${i+1}</button> <span style=\"margin-left:6px;\">${p.replace(/</g,'&lt;')}</span></div>`
  ).join('');
  window.__promptSuggestions = prompts;
}
function usePromptSuggestion(idx){
  const prompts = window.__promptSuggestions || [];
  if (!prompts[idx]) return;
  document.querySelector('textarea[name="service_description"]').value = prompts[idx];
}
function onServicePick(sel){
  const row = sel.closest('.grid');
  const svc = services.find(s => String(s.id) === String(sel.value));
  if (!svc) return;
  row.querySelector('input[name="item_name[]"]').value = svc.service_name || '';
  row.querySelector('input[name="item_desc[]"]').value = svc.service_desc || '';
  row.querySelector('input[name="unit_price[]"]').value = svc.unit_price || 0;
  row.querySelector('input[name="tax_rate[]"]').value = svc.tax_rate || 18;
}

document.getElementById('clientSelect').addEventListener('change', function(){
  const c = clients.find(x => String(x.id) === String(this.value));
  if (!c) return;
  document.getElementById('clientName').value = c.name || '';
  document.getElementById('clientEmail').value = c.email || '';
  document.getElementById('clientPhone').value = c.phone || '';
  document.getElementById('clientCompany').value = c.company || '';
  document.getElementById('clientProfile').value = c.notes || '';
  renderPromptSuggestions(c);
});

async function aiSuggest(form){
  const fd = new FormData();
  fd.append('_csrf', form.querySelector('input[name="_csrf"]').value);
  fd.append('action','ai_suggest');
  fd.append('service_description', form.querySelector('textarea[name="service_description"]').value || '');
  fd.append('industry', <?= json_encode($company['industry'] ?? 'general') ?>);
  fd.append('client_profile', form.querySelector('textarea[name="client_profile"]').value || '');
  fd.append('services_context', services.map(s=>`${s.service_name}: ${s.service_desc||''}`).join('\n'));
  fd.append('services_selected_json', JSON.stringify(Array.from(form.querySelectorAll('#itemsWrap .grid')).map((row)=>({
    name: (row.querySelector('input[name=\"item_name[]\"]')||{}).value || '',
    description: (row.querySelector('input[name=\"item_desc[]\"]')||{}).value || '',
    price: parseFloat((row.querySelector('input[name=\"unit_price[]\"]')||{}).value || '0') || 0,
    features: (row.querySelector('input[name=\"item_desc[]\"]')||{}).value || ''
  }))));  
  fd.append('user_company_name', <?= json_encode($company['company_name'] ?? 'Your Company') ?>);
  fd.append('user_company_description', <?= json_encode($company['industry'] ?? '') ?>);
  fd.append('user_company_services', <?= json_encode($company['services_list'] ?? '') ?>);
  fd.append('user_company_tone', 'professional');
  fd.append('client_company_name', form.querySelector('input[name=\"client_company\"]').value || form.querySelector('input[name=\"client_name\"]').value || 'Client Company');
  fd.append('client_industry', <?= json_encode($company['industry'] ?? 'general') ?>);
  fd.append('optional_user_notes', form.querySelector('textarea[name=\"notes\"]').value || '');
  const res = await fetch('quote_generator.php', {method:'POST', body:fd, headers:{'X-Requested-With':'XMLHttpRequest'}});
  const data = await res.json();
  if(!data.items) return;
  const hint = form.querySelector('.ai-hint');
  if (hint && data.price_range) hint.textContent = `AI Price Range: ${data.price_range.min} - ${data.price_range.max}`;
  if (Array.isArray(data.prompt_suggestions) && data.prompt_suggestions.length) {
    const holder = document.getElementById('promptSuggestions');
    holder.innerHTML += data.prompt_suggestions.map((p, i)=>`<div style=\"margin-bottom:6px;\"><button type=\"button\" class=\"btn btn-xs btn-secondary\" onclick=\"document.querySelector('textarea[name=\\\"service_description\\\"]').value = this.nextElementSibling.textContent\">Use AI Prompt ${i+1}</button> <span style=\"margin-left:6px;\">${String(p).replace(/</g,'&lt;')}</span></div>`).join('');
  }
  document.getElementById('aiPromptField').value = form.querySelector('textarea[name="service_description"]').value || '';
  document.getElementById('aiContentField').value = data.detailed_quote || data.scope || '';
  const rows = form.querySelectorAll('input[name="item_name[]"]');
  data.items.forEach((it, i)=>{
    if(!rows[i]) addServiceRow();
    form.querySelectorAll('input[name="item_name[]"]')[i].value = it.item_name || '';
    form.querySelectorAll('input[name="item_desc[]"]')[i].value = it.item_desc || '';
    form.querySelectorAll('input[name="quantity[]"]')[i].value = it.quantity || 1;
    form.querySelectorAll('input[name="unit_price[]"]')[i].value = it.unit_price || 0;
    form.querySelectorAll('input[name="tax_rate[]"]')[i].value = it.tax_rate || 18;
  });
}

addServiceRow();addServiceRow();
renderPromptSuggestions();
</script>
<?php include __DIR__ . '/layouts/footer.php'; ?>
