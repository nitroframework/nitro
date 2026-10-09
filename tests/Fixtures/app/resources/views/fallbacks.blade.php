<!DOCTYPE html>
<html>
<head>
    <title>Fallbacks</title>
</head>
<body>
    <nitro:counter />
    <nitro:flaky :fail="request('flaky') === 'fail'" :forbidden="request('flaky') === 'forbidden'" />
    <nitro:gauge :fail="request('gauge') === 'fail'" />
    @nitroScripts
</body>
</html>
