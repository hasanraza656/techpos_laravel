<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="referrer" content="no-referrer"><meta name="robots" content="noindex"><title>Upload payment proof · Tech POS</title>
<style>body{font:16px system-ui;background:#f4f7f6;color:#15382f;padding:24px}main{max-width:440px;margin:40px auto;background:white;border-radius:20px;padding:28px}label,input,button{display:block;width:100%;box-sizing:border-box;margin:20px 0}button{background:#126c51;color:white;padding:15px;border:0;border-radius:9px;font:inherit}.error{color:#a52929}progress{width:100%}</style></head><body><main>
<small>TECH POS · SECURE UPLOAD</small>
@if($success ?? false)<h1>Screenshot received</h1><p>You can close this page. Return to your desktop to finish submitting the payment for review.</p>
@else<h1>Add your screenshot</h1><p>Choose a clear payment screenshot. PNG, JPEG or WebP, up to 5 MB. This link expires after 15 minutes and can be used once.</p>
@foreach($errors->all() as $error)<p class="error" role="alert">{{ $error }}</p>@endforeach
<form method="post" enctype="multipart/form-data" id="upload">@csrf<label for="proof">Payment screenshot</label><input required id="proof" type="file" name="proof" accept="image/png,image/jpeg,image/webp"><button type="submit">Upload screenshot</button><progress hidden></progress><p role="status" id="status"></p></form>
<script nonce="{{ request()->attributes->get('csp_nonce') }}">document.querySelector('form').addEventListener('submit',function(){this.querySelector('button').disabled=true;this.querySelector('progress').hidden=false;document.querySelector('#status').textContent='Uploading screenshot. Please keep this page open.';});</script>
@endif</main></body></html>
