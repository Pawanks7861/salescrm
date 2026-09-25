<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>@yield('title') | CRM</title>
    {{-- Self-contained: no database, Vite build or external assets, so it renders during maintenance and outages. --}}
    <style>
        *{box-sizing:border-box}
        html,body{margin:0;min-height:100vh}
        body{display:flex;align-items:center;justify-content:center;padding:24px;background:#0b0f1a radial-gradient(1200px 600px at 85% -10%,rgba(99,102,241,.07),transparent 60%);color:#a5aec4;font:14px/1.5 Inter,ui-sans-serif,system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;-webkit-font-smoothing:antialiased}
        main{max-width:440px;width:100%;text-align:center;background:#151b2b;border:1px solid #273149;border-radius:16px;padding:40px 32px}
        .code{margin:0;font-size:48px;font-weight:700;line-height:1;color:#a09bff}
        h1{margin:14px 0 0;font-size:20px;font-weight:600;color:#f5f7ff}
        p.msg{margin:8px 0 0}
        .actions{margin-top:28px;display:flex;gap:8px;justify-content:center;flex-wrap:wrap}
        .btn{display:inline-flex;align-items:center;height:36px;padding:0 16px;border-radius:8px;font-weight:500;text-decoration:none;border:1px solid #273149;color:#f5f7ff;background:#181f31;cursor:pointer;font:inherit}
        .btn:hover{border-color:#3a4560}
        .btn.primary{background:#6366f1;border-color:#6366f1}
        .btn.primary:hover{background:#5457e6}
        .btn:focus-visible{outline:2px solid #7c74ff;outline-offset:2px}
    </style>
</head>
<body>
    <main role="main">
        <p class="code">@yield('code')</p>
        <h1>@yield('title')</h1>
        <p class="msg">@yield('message')</p>
        <div class="actions">
            @hasSection('actions')
                @yield('actions')
            @else
                <a class="btn primary" href="{{ url('/') }}">Home</a>
            @endif
        </div>
    </main>
</body>
</html>
