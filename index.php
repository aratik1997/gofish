<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Go Fish — Pond Party</title>
<link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>🎣</text></svg>">
<link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="landing-body">

<div class="pond-backdrop"></div>

<main class="landing-card">
    <h1 class="landing-title">🎣 Go Fish <span class="subtitle">Pond Party</span></h1>
    <p class="landing-tagline">Gather 2–6 anglers, cast your questions, and reel in four-of-a-kind sets.</p>

    <div class="tabs">
        <button class="tab-btn active" data-tab="create">Create Game</button>
        <button class="tab-btn" data-tab="join">Join Game</button>
    </div>

    <form id="create-form" class="tab-panel active">
        <label for="create-name">Your name</label>
        <input id="create-name" type="text" maxlength="20" placeholder="e.g. Captain Ahab" required>
        <button type="submit" class="primary-btn">🐟 Create a Pond</button>
        <p id="create-error" class="form-error"></p>
    </form>

    <form id="join-form" class="tab-panel">
        <label for="join-name">Your name</label>
        <input id="join-name" type="text" maxlength="20" placeholder="e.g. Old Salt" required>
        <label for="join-code">Room code</label>
        <input id="join-code" type="text" maxlength="5" placeholder="e.g. F1SHY" style="text-transform:uppercase" required>
        <button type="submit" class="primary-btn">🎣 Join Pond</button>
        <p id="join-error" class="form-error"></p>
    </form>
</main>

<script src="assets/js/app.js"></script>
<script>GoFish.initLanding();</script>
</body>
</html>
