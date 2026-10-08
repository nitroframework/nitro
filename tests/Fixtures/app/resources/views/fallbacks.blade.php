<!DOCTYPE html>
<html>
<head>
    <title>Fallbacks</title>
</head>
<body>
    <fusion:counter />
    <fusion:flaky :fail="request('flaky') === 'fail'" :forbidden="request('flaky') === 'forbidden'" />
    <fusion:gauge :fail="request('gauge') === 'fail'" />
    @fusionScripts
</body>
</html>
