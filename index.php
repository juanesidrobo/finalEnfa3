<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Topología de Red</title>
    <link rel="stylesheet" href="css/styles.css">
</head>
<body>
    <h1>Topología de Red</h1>
    <div class="container">
        <h2>Switches</h2>
        <div id="switches">
            Cargando switches...
        </div>
        <h2>Enlaces</h2>
        <div id="links">
            Cargando enlaces...
        </div>
    </div>
    <script>
        // Carga de switches
        fetch('api/get_switches.php')
            .then(response => response.json())
            .then(data => {
                const switchesDiv = document.getElementById('switches');
                switchesDiv.innerHTML = '<pre>' + JSON.stringify(data, null, 2) + '</pre>';
            })
            .catch(error => {
                document.getElementById('switches').innerHTML = 'Error al cargar switches';
                console.error(error);
            });

        // Carga de enlaces
        fetch('api/get_links.php')
            .then(response => response.json())
            .then(data => {
                const linksDiv = document.getElementById('links');
                linksDiv.innerHTML = '<pre>' + JSON.stringify(data, null, 2) + '</pre>';
            })
            .catch(error => {
                document.getElementById('links').innerHTML = 'Error al cargar enlaces';
                console.error(error);
            });
    </script>
</body>
</html>
