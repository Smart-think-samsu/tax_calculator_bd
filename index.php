<?php
declare(strict_types=1);

/**
 * Bangladesh Income Tax Calculator (single file)
 * - AY 2025-26: 350,000@0%, 100,000@5%, 400,000@10%, 500,000@15%, 500,000@20%, 2,000,000@25%, REST 30%
 * - AY 2026-27 / 2027-28: 375,000@0%, 300,000@10%, 400,000@15%, 500,000@20%, 2,000,000@25%, REST 30%
 * - General Exemption applied FIRST (default 200,000)
 * - Minimum tax by zone: city 5,000 | district 3,000 | other/new 1,000
 * - Optional: investment rebate, AIT (advance tax), wealth surcharge (by net worth)
 */

/* ---------------- Slabs by Assessment Year ---------------- */
function slabs_by_year(string $year): array {
    $ay_2025_26 = [
        ['amount'=>350000,  'rate'=>0],
        ['amount'=>100000,  'rate'=>5],
        ['amount'=>400000,  'rate'=>10],
        ['amount'=>500000,  'rate'=>15],
        ['amount'=>500000,  'rate'=>20],
        ['amount'=>2000000, 'rate'=>25],
    ];
    $ay_2026_on = [
        ['amount'=>375000,  'rate'=>0],
        ['amount'=>300000,  'rate'=>10],
        ['amount'=>400000,  'rate'=>15],
        ['amount'=>500000,  'rate'=>20],
        ['amount'=>2000000, 'rate'=>25],
    ];
    return in_array($year, ['2026-27','2027-28'], true) ? $ay_2026_on : $ay_2025_26;
}

/* --------------- Wealth surcharge brackets (optional) --------------- */
function surcharge_rate_by_networth(float $netWorth): float {
    $brackets = [
        ['up_to'=> 40000000,   'rate'=>0],
        ['up_to'=>100000000,   'rate'=>10],
        ['up_to'=>200000000,   'rate'=>20],
        ['up_to'=>500000000,   'rate'=>30],
        ['up_to'=>PHP_INT_MAX, 'rate'=>35],
    ];
    foreach ($brackets as $b) if ($netWorth <= $b['up_to']) return (float)$b['rate'];
    return 0.0;
}

/* ---------------- Core calculation ---------------- */
function calc_tax(array $in): array {
    $year        = (string)($in['year'] ?? '2025-26'); // '2025-26' | '2026-27' | '2027-28'
    $basic       = (float)($in['basic_salary'] ?? 0);
    $hra         = (float)($in['hra'] ?? 0);
    $conv        = (float)($in['conveyance'] ?? 0);
    $medical     = (float)($in['medical'] ?? 0);
    $bonus       = (float)($in['bonus'] ?? 0);
    $overtime    = (float)($in['overtime'] ?? 0);
    $otherIncome = (float)($in['other_income'] ?? 0);

    $aitPaid     = (float)($in['ait_paid'] ?? 0);
    $investment  = (float)($in['eligible_investment'] ?? 0);
    $rebateRate  = max(0.0, (float)($in['rebate_rate'] ?? 15)); // %
    $netWorth    = (float)($in['net_worth'] ?? 0);

    // General exemption + minimum tax zone
    $generalExemption = (float)($in['general_exemption'] ?? 200000); // default: 200,000
    $minTaxZone       = (string)($in['min_tax_zone'] ?? 'district'); // city|district|other|new
    $minTaxMap = ['city'=>5000.0,'district'=>3000.0,'other'=>1000.0,'new'=>1000.0];
    $minTax = $minTaxMap[$minTaxZone] ?? 3000.0;

    // Total income (yearly)
    $total = max(0.0, $basic + $hra + $conv + $medical + $bonus + $overtime + $otherIncome);

    // Apply GENERAL EXEMPTION first
    $taxBaseAfterGeneral = max(0.0, $total - $generalExemption);

    // Slab calculation on remaining base
    $slabs = slabs_by_year($year);
    $zeroLimit = (float)$slabs[0]['amount'];

    $remaining = $taxBaseAfterGeneral;
    $taxBeforeRebate = 0.0;
    $bands = [];
    foreach ($slabs as $s) {
        if ($remaining <= 0) break;
        $portion = min($remaining, (float)$s['amount']);
        $lineTax = $portion * ((float)$s['rate'] / 100.0);
        $taxBeforeRebate += $lineTax;
        $bands[] = ['portion'=>$portion,'rate'=>$s['rate'],'tax'=>$lineTax];
        $remaining -= $portion;
    }
    if ($remaining > 0) {
        $restRate = 30.0;
        $lineTax = $remaining * ($restRate / 100.0);
        $taxBeforeRebate += $lineTax;
        $bands[] = ['portion'=>$remaining,'rate'=>$restRate,'tax'=>$lineTax];
    }

    // Rebate (capped at 25% of total income)
    $cap = $total * 0.25;
    $eligibleBase = min($investment, $cap);
    $taxRebate = min($taxBeforeRebate, $eligibleBase * ($rebateRate / 100.0));

    // Wealth surcharge on post-rebate tax
    $postRebateTax = max(0.0, $taxBeforeRebate - $taxRebate);
    $surchargeRate = surcharge_rate_by_networth($netWorth);
    $surcharge = $postRebateTax * ($surchargeRate / 100.0);

    // Minimum tax (only if income exceeds general exemption)
    $taxAfterSurcharge = $postRebateTax + $surcharge;
    if ($total > $generalExemption && $taxAfterSurcharge < $minTax) {
        $taxAfterSurcharge = $minTax;
    }

    // Net after AIT
    $netPayable = max(0.0, $taxAfterSurcharge - $aitPaid);

    return [
        'inputs' => [
            'year'=>$year,'general_exemption'=>$generalExemption,'min_tax_zone'=>$minTaxZone,
            'basic_salary'=>$basic,'hra'=>$hra,'conveyance'=>$conv,'medical'=>$medical,
            'bonus'=>$bonus,'overtime'=>$overtime,'other_income'=>$otherIncome,
            'eligible_investment'=>$investment,'rebate_rate'=>$rebateRate,
            'ait_paid'=>$aitPaid,'net_worth'=>$netWorth,
        ],
        'summary' => [
            'total_earnings'=>round($total,2),
            'general_exemption'=>round($generalExemption,2),
            'net_taxable_after_general'=>round($taxBaseAfterGeneral,2),
            'zero_percent_limit'=>round($zeroLimit,2),
            'income_subject_to_slab'=>round($taxBaseAfterGeneral,2),
            'tax_before_rebate'=>round($taxBeforeRebate,2),
            'eligible_investment_capped'=>round($eligibleBase,2),
            'calculated_tax_rebate'=>round($taxRebate,2),
            'surcharge_rate'=>$surchargeRate,
            'surcharge'=>round($surcharge,2),
            'gross_payable_after_rebate_surcharge'=>round($taxAfterSurcharge,2),
            'ait_paid'=>round($aitPaid,2),
            'net_tax_payable'=>round($netPayable,2),
        ],
        'bands'=>array_map(fn($b)=>[
            'portion'=>round($b['portion'],2),'rate'=>$b['rate'],'tax'=>round($b['tax'],2)
        ], $bands),
    ];
}

/* ---------------- Router ---------------- */
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path   = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

if ($path === '/calculate' && $method === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    $raw = file_get_contents('php://input') ?: '{}';
    $data = json_decode($raw, true);
    if (!is_array($data)) $data = $_POST;
    echo json_encode(calc_tax($data), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

/* ---------------- UI ---------------- */
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Bangladesh Income Tax Calculator</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
:root{--bg:#f8fafc;--card:#f1f5f9;--ink:#0f172a;--muted:#475569;--brand:#2563eb}
body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;background:var(--bg);color:var(--ink);margin:0}
.wrap{max-width:1100px;margin:32px auto;padding:0 16px}
h1{margin:0 0 18px;font-size:28px}
.section{margin-top:18px;border:1px solid #e2e8f0;background:#fff;border-radius:12px;overflow:hidden}
.section h3{margin:0;padding:10px 16px;border-bottom:1px solid #e2e8f0;text-align:center;color:#1e3a8a}
.card{padding:18px;background:#fff}
.grid{display:grid;grid-template-columns:1fr 1fr;gap:14px}
label{font-size:13px;color:var(--muted);display:block;margin-bottom:6px}
input,select{width:100%;padding:10px;border:1px solid #cbd5e1;border-radius:10px;background:white}
.full{grid-column:1/-1}
button{padding:12px 18px;border:0;border-radius:10px;background:var(--brand);color:white;font-weight:700;cursor:pointer}
.row{display:flex;justify-content:space-between;gap:12px;padding:8px 12px;border-bottom:1px solid #e2e8f0}
.row:last-child{border-bottom:0}
.kpi{font-weight:700}
.badge{background:#dcfce7;color:#166534;display:inline-block;border-radius:999px;padding:6px 12px;font-weight:800}
.small{font-size:12px;color:#64748b}
.table{width:100%;border-collapse:collapse}
.table th,.table td{padding:10px;border-bottom:1px solid #e2e8f0}
.table th{text-align:left;color:#334155;font-size:13px}
@media(max-width:760px){.grid{grid-template-columns:1fr}}
</style>
</head>
<body>
<div class="wrap">
  <h1>Bangladesh Income Tax Calculator</h1>

  <!-- Income Details -->
  <div class="section">
    <h3>Income Details (Fiscal Year)</h3>
    <div class="card">
      <form id="taxForm" class="grid">
        <div>
          <label>Assessment Year</label>
          <select name="year">
            <option value="2025-26">2025-26</option>
            <option value="2026-27">2026-27</option>
            <option value="2027-28">2027-28</option>
          </select>
        </div>
        <div>
          <label>Minimum Tax Zone</label>
          <select name="min_tax_zone">
            <option value="city">City Corporation / Cantonment (৳5,000)</option>
            <option value="district" selected>District HQ / Municipality (৳3,000)</option>
            <option value="other">Other Area (৳1,000)</option>
            <option value="new">New Taxpayer (৳1,000)</option>
          </select>
        </div>

        <div>
          <label>General Exemption (Tax-free)</label>
          <input type="number" name="general_exemption" value="200000" min="0" step="1">
          <div class="small">Applied before slabs (e.g., 200,000)</div>
        </div>
        <div class="full"></div>

        <div>
          <label>Basic Salary</label>
          <input type="number" name="basic_salary" placeholder="e.g. 500000" min="0" step="1">
        </div>
        <div>
          <label>House Rent Allowance (HRA)</label>
          <input type="number" name="hra" placeholder="e.g. 240000" min="0" step="1">
        </div>

        <div>
          <label>Conveyance Allowance</label>
          <input type="number" name="conveyance" placeholder="e.g. 30000" min="0" step="1">
        </div>
        <div>
          <label>Medical Allowance</label>
          <input type="number" name="medical" placeholder="e.g. 24000" min="0" step="1">
        </div>

        <div>
          <label>Bonuses (Performance / Yearly / Festival)</label>
          <input type="number" name="bonus" placeholder="e.g. 100000" min="0" step="1">
        </div>
        <div>
          <label>Overtime Allowance</label>
          <input type="number" name="overtime" placeholder="e.g. 20000" min="0" step="1">
        </div>

        <div>
          <label>Other Income (e.g., Leave Encashment)</label>
          <input type="number" name="other_income" placeholder="e.g. 50000" min="0" step="1">
        </div>
        <div>
          <label>Advance Income Tax (AIT) already paid</label>
          <input type="number" name="ait_paid" placeholder="e.g. 15000" min="0" step="1">
        </div>

        <div>
          <label>Eligible Investment for Rebate</label>
          <input type="number" name="eligible_investment" placeholder="e.g. 100000" min="0" step="1">
          <div class="small">Capped at 25% of total income.</div>
        </div>
        <div>
          <label>Rebate Rate (%)</label>
          <input type="number" name="rebate_rate" value="15" min="0" max="100" step="0.01">
        </div>

        <div class="full">
          <label>Net Worth (for wealth surcharge, optional)</label>
          <input type="number" name="net_worth" placeholder="e.g. 60000000" min="0" step="1">
        </div>

        <div class="full">
          <button type="submit">Calculate</button>
        </div>
      </form>
    </div>
  </div>

  <!-- Live Results -->
  <div class="section" style="margin-top:18px;">
    <h3>Live Results</h3>
    <div class="card" id="results" style="display:none">
      <div class="row"><div>Total Earnings:</div><div class="kpi" id="r_total">—</div></div>
      <div class="row"><div>Tax-Free Income (General Exemption):</div><div class="kpi" id="r_exm">—</div></div>
      <div class="row"><div>Net Taxable Income (After General Exemption):</div><div class="kpi" id="r_net_after_exm">—</div></div>
      <div class="row"><div>0% Tax Limit (First Slab):</div><div class="kpi" id="r_zero">—</div></div>
      <div class="row"><div>Income Subject to Slab Calculation:</div><div class="kpi" id="r_slab_base">—</div></div>
      <div class="row"><div>Total Tax Before Rebate:</div><div class="kpi" id="r_before_rebate">—</div></div>
      <div class="row"><div>Eligible Investment for Rebate (Capped):</div><div class="kpi" id="r_inv_cap">—</div></div>
      <div class="row"><div>Calculated Tax Rebate:</div><div class="kpi" id="r_rebate">—</div></div>
      <div class="row"><div>Wealth Surcharge:</div><div class="kpi" id="r_surcharge">—</div></div>
      <div class="row"><div>Gross Payable (after rebate + surcharge):</div><div class="kpi" id="r_gross">—</div></div>
      <div class="row"><div>Advance Income Tax Paid (AIT):</div><div class="kpi" id="r_ait">—</div></div>
      <div class="row" style="align-items:center;border-top:2px solid #cbd5e1;margin-top:8px;padding-top:10px">
        <div style="font-weight:800;color:#b91c1c">Net Tax Payable:</div>
        <div class="badge" id="r_net">BDT 0.00</div>
      </div>
    </div>
  </div>

  <!-- Slab Breakdown -->
  <div class="section" style="margin-top:18px;">
    <h3>Slab Breakdown</h3>
    <div class="card">
      <table class="table" id="slabTable">
        <thead><tr><th>Portion</th><th>Rate</th><th>Tax</th></tr></thead>
        <tbody></tbody>
      </table>
      <div class="small" id="surchargeNote" style="margin-top:8px;color:#0f766e"></div>
    </div>
  </div>
</div>

<script>
const money = n => 'BDT ' + (Number(n).toLocaleString('en-IN', {minimumFractionDigits:2, maximumFractionDigits:2}));
function S(id){ return document.getElementById(id); }

const form = document.getElementById('taxForm');
const resBox = document.getElementById('results');
const tbody = document.querySelector('#slabTable tbody');

form.addEventListener('submit', async (e)=>{
  e.preventDefault();
  const fd = new FormData(form);
  const payload = Object.fromEntries(fd.entries());

  // numbers
  ['basic_salary','hra','conveyance','medical','bonus','overtime','other_income',
   'ait_paid','eligible_investment','rebate_rate','net_worth','general_exemption']
   .forEach(k => payload[k] = Number(payload[k] || 0));

  payload['min_tax_zone'] = fd.get('min_tax_zone') || 'district';

  const r = await fetch('/calculate', {
    method:'POST',
    headers:{'Content-Type':'application/json'},
    body: JSON.stringify(payload)
  });
  const data = await r.json();

  const d = data.summary;
  S('r_total').textContent        = money(d.total_earnings);
  S('r_exm').textContent          = money(d.general_exemption);
  S('r_net_after_exm').textContent= money(d.net_taxable_after_general);
  S('r_zero').textContent         = money(d.zero_percent_limit);
  S('r_slab_base').textContent    = money(d.income_subject_to_slab);
  S('r_before_rebate').textContent= money(d.tax_before_rebate);
  S('r_inv_cap').textContent      = money(d.eligible_investment_capped);
  S('r_rebate').textContent       = money(d.calculated_tax_rebate);
  S('r_surcharge').textContent    = money(d.surcharge);
  S('r_gross').textContent        = money(d.gross_payable_after_rebate_surcharge);
  S('r_ait').textContent          = money(d.ait_paid);
  S('r_net').textContent          = money(d.net_tax_payable);

  // table
  tbody.innerHTML = '';
  (data.bands||[]).forEach(b=>{
    const tr = document.createElement('tr');
    tr.innerHTML = `<td>${money(b.portion)}</td><td>${b.rate}%</td><td>${money(b.tax)}</td>`;
    tbody.appendChild(tr);
  });

  const note = d.surcharge > 0 ? `Wealth surcharge (${d.surcharge_rate}%): ${money(d.surcharge)}`
                               : `No wealth surcharge applied.`;
  document.getElementById('surchargeNote').textContent = note;

  resBox.style.display = 'block';
});
</script>
</body>
</html>
