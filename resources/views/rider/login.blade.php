<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<meta name="csrf-token" content="{{ csrf_token() }}">
<title>Rider Login | Bearly</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">

<style>
:root{--brown:#4b2e1f;--brown2:#6b4226;--orange:#e9822c;--cream:#fffaf3;--line:#eadfd2;--text:#2e211a;--muted:#75665d;--white:#fff;--green:#2d7a4f}
*{box-sizing:border-box}body{margin:0;font-family:Poppins,Arial,sans-serif;background:linear-gradient(145deg,#fffaf3,#f7eee4);color:var(--text)}
a{text-decoration:none;color:inherit}.shell{min-height:100vh;display:grid;grid-template-columns:1.05fr .95fr}
.hero{padding:64px clamp(28px,6vw,90px);background:linear-gradient(145deg,var(--brown),#2f1c13);color:white;display:flex;flex-direction:column;justify-content:space-between}
.brand{font-weight:800;font-size:24px;letter-spacing:-.5px}.eyebrow{color:#ffc28a;font-weight:700;text-transform:uppercase;letter-spacing:.12em;font-size:12px}
.hero h1{font-size:clamp(38px,5vw,68px);line-height:1.02;margin:14px 0 18px;max-width:700px}.hero p{max-width:660px;color:#eaded6;line-height:1.7}
.panel{padding:48px clamp(24px,5vw,72px);display:flex;align-items:center}.card{width:min(100%,620px);margin:auto;background:white;border:1px solid var(--line);border-radius:24px;padding:30px;box-shadow:0 18px 50px rgba(62,39,25,.09)}
.card h2{margin:0 0 8px}.muted{color:var(--muted)}.actions{display:grid;gap:12px;margin-top:24px}.btn{border:0;border-radius:12px;padding:14px 18px;font:inherit;font-weight:700;cursor:pointer;text-align:center}.btn-primary{background:var(--orange);color:white}.btn-secondary{background:#f6eee7;color:var(--brown);border:1px solid var(--line)}
.feature{display:flex;gap:12px;margin-top:22px}.feature b{display:block}.dot{width:34px;height:34px;min-width:34px;border-radius:10px;background:#fff1e5;color:var(--orange);display:grid;place-items:center;font-weight:800}
.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px}.full{grid-column:1/-1}.field label{display:block;font-size:13px;font-weight:700;margin-bottom:7px}.field input,.field select{width:100%;padding:12px 13px;border:1px solid #dbcdbf;border-radius:10px;font:inherit;background:white}.field input:focus,.field select:focus{outline:2px solid rgba(233,130,44,.18);border-color:var(--orange)}
.notice{padding:14px 16px;border-radius:12px;background:#fff2df;border:1px solid #f1c995;color:#71471f;margin:16px 0}.success{background:#edf8f1;border-color:#b9dfc7;color:#255f3f}
.toplink{display:inline-block;margin-bottom:18px;color:var(--brown);font-weight:700}.small{font-size:13px}.divider{height:1px;background:var(--line);margin:22px 0}
@media(max-width:880px){.shell{grid-template-columns:1fr}.hero{min-height:auto;padding:38px 24px}.panel{padding:28px 18px}.form-grid{grid-template-columns:1fr}.full{grid-column:auto}}
</style>

</head>
<body>
<div class="shell">
<section class="hero">
<div class="brand">Bearly</div>
<div>
<div class="eyebrow">Approved Riders</div>
<h1>Your pickups and deliveries, in one place.</h1>
<p>Access assigned pickups, delivery jobs, earnings, history, and messages after your Logistics partner activates your account.</p>
</div>
<p class="small">Bearly E-Commerce ERP · Logistics & Delivery Network</p>
</section>
<section class="panel">
<div class="card">
<a class="toplink" href="{{ route('rider.landing') }}">← Back to Rider Portal</a>
<h2>Rider Login</h2>
<p class="muted">Sign in after your selected Logistics / Sorting Center has approved your Rider application.</p>
@if ($errors->any())
<div class="notice">{{ $errors->first() }}</div>
@endif
<form method="POST" action="{{ route('rider.login.submit') }}">
@csrf
<div class="form-grid">
<div class="field full"><label>Email Address</label><input type="email" name="email" value="{{ old('email') }}" required></div>
<div class="field full"><label>Password</label><input type="password" name="password" required minlength="6"></div>
<div class="full"><button class="btn btn-primary" style="width:100%" type="submit">Sign In as Rider</button></div>
</div>
</form>
<div class="divider"></div>
<p class="small muted">Need a Rider account? <a href="{{ route('rider.register') }}" style="color:var(--orange);font-weight:700">Register under a Logistics partner</a></p>
</div>
</section>
</div>
</body>
</html>