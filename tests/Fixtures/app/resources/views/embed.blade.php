<!DOCTYPE html>
<html>
<head>
    <title>Embed</title>
</head>
<body>
    <nitro:counter :step="2" />
    <nitro:todo-list :todos="[['id' => 1, 'title' => 'First', 'done' => false], ['id' => 2, 'title' => 'Second', 'done' => true]]" />
    @nitroScripts
</body>
</html>
