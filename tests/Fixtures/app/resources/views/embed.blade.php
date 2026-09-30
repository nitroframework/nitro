<!DOCTYPE html>
<html>
<head>
    <title>Embed</title>
</head>
<body>
    <fusion:counter :step="2" />
    <fusion:todo-list :todos="[['id' => 1, 'title' => 'First', 'done' => false], ['id' => 2, 'title' => 'Second', 'done' => true]]" />
    @fusionScripts
</body>
</html>
