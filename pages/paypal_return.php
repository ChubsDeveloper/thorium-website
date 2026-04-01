<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Processing Payment...</title>
    <style>
        body {
            margin: 0;
            padding: 0;
            background: linear-gradient(135deg, #1a1a1a 0%, #2d2d2d 100%);
            color: #fff;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .container {
            text-align: center;
            padding: 2rem;
        }
        .spinner {
            width: 50px;
            height: 50px;
            border: 3px solid #34d399;
            border-top: 3px solid transparent;
            border-radius: 50%;
            animation: spin 1s linear infinite;
            margin: 0 auto 1.5rem;
        }
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        h1 {
            font-size: 1.5rem;
            margin: 0 0 0.5rem;
        }
        p {
            color: #aaa;
            margin: 0.5rem 0 1rem;
        }
        .notice {
            background: rgba(52, 211, 153, 0.1);
            border: 1px solid #34d399;
            border-radius: 8px;
            padding: 1rem;
            margin-top: 2rem;
            font-size: 0.9rem;
            color: #34d399;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="spinner"></div>
        <h1>Processing Your Payment</h1>
        <p>Please wait while we confirm your donation...</p>
        
        <div class="notice">
            You will be redirected automatically.<br>
            If not, <a href="#" id="fallback-link" style="color: #34d399; text-decoration: underline;">click here</a>
        </div>
    </div>

    <script>
        (function() {
            // Get tx parameter from URL
            const urlParams = new URLSearchParams(window.location.search);
            const tx = urlParams.get('tx');
            
            if (!tx) {
                document.querySelector('p').textContent = 'Error: No transaction ID found.';
                return;
            }

            // Set fallback link
            const fallbackLink = document.getElementById('fallback-link');
            const handlerUrl = `/api/paypal/return_handler.php?tx=${encodeURIComponent(tx)}`;
            fallbackLink.href = handlerUrl;

            // Auto-redirect after 2 seconds to give PayPal time to send webhook
            setTimeout(() => {
                window.location.href = handlerUrl;
            }, 2000);
        })();
    </script>
</body>
</html>