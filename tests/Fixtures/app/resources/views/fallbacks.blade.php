<!DOCTYPE html>
<html>
<head>
    <title>Fallbacks</title>
</head>
<body>
    <nitro:counter />
    <nitro:flaky :fail="request('flaky') === 'fail'" :refuse="(string) request('refuse')" />
    <nitro:gauge :fail="request('gauge') === 'fail'" />
    @nitroScripts
</body>
</html>
