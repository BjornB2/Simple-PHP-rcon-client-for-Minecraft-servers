<?php
session_start();

/*
 * ========================
 * RCON FUNCTIONS
 * ========================
 */

/**
 * Connects to the RCON server, authenticates and executes a command.
 *
 * @param string $host     The server host (IP or domain)
 * @param int    $port     The server port
 * @param string $password The RCON password
 * @param string $command  The command to execute
 *
 * @return string          The response from the command or an error message
 */
function rcon_query($host, $port, $password, $command) {
    $socket = @fsockopen($host, $port, $errno, $errstr, 3);
    if (!$socket) {
        return "Cannot connect: $errstr ($errno)";
    }
    stream_set_timeout($socket, 3);
    // Authenticate
    $authReqId = rand(1, 100000);
    rcon_sendPacket($socket, $authReqId, 3, $password);
    $authResponse = rcon_getResponse($socket);
    if (!$authResponse || $authResponse['id'] == -1) {
        fclose($socket);
        return "Authentication failed.";
    }
    // Execute the command
    $cmdReqId = rand(1, 100000);
    rcon_sendPacket($socket, $cmdReqId, 2, $command);
    $response = rcon_getResponse($socket);
    fclose($socket);
    return $response ? $response['body'] : "";
}

/**
 * Sends an RCON packet to the server.
 */
function rcon_sendPacket($socket, $reqId, $type, $payload) {
    $packetBody = pack("V", $reqId) . pack("V", $type) . $payload . "\x00\x00";
    $packetLength = strlen($packetBody);
    $packet = pack("V", $packetLength) . $packetBody;
    fwrite($socket, $packet);
}

/**
 * Reads a packet from the RCON server.
 */
function rcon_getResponse($socket) {
    $data = fread($socket, 4);
    if (strlen($data) != 4) return false;
    $unpack = unpack("Vlength", $data);
    $length = $unpack['length'];
    if ($length < 10) return false;
    $data = fread($socket, $length);
    if (strlen($data) != $length) return false;
    $unpacked = unpack("Vid/Vtype", $data);
    if (!$unpacked || !isset($unpacked['id'], $unpacked['type'])) return false;
    $body = substr($data, 8, -2);
    return ['id' => $unpacked['id'], 'type' => $unpacked['type'], 'body' => $body];
}

/*
 * ========================
 * HELPER FUNCTIONS TO PARSE SERVER OUTPUT
 * ========================
 */

/**
 * Parses the player list.
 * Splitst op komma en/of newlines, verwijdert alle tekens tot en met de eerste dubbele punt en spatie
 * (leidende groepsinformatie) en verwijdert "[AFK]" uit de naam.
 * Indien "[AFK]" voorkomt, wordt dit als flag opgeslagen.
 */
function parsePlayerList($response) {
    $players = [];
    $parts = explode(":", $response, 2);
    if (count($parts) < 2) return $players;
    $items = preg_split("/[\r\n,]+/", $parts[1]);
    foreach ($items as $item) {
        $item = trim($item);
        if ($item === "") continue;
        if (strpos($item, ":") !== false) {
            $item = preg_replace('/^.*?:\s*/', '', $item);
        }
        $afk = false;
        if (stripos($item, "[AFK]") !== false) {
            $afk = true;
            $item = trim(str_ireplace("[AFK]", "", $item));
        }
        $players[] = ['name' => $item, 'isOp' => false, 'afk' => $afk];
    }
    return $players;
}

/**
 * Parses een lijst (zoals voor ops) door te splitsen op komma, CR en LF.
 */
function parseListFromResponse($response) {
    $list = [];
    $parts = explode(":", $response, 2);
    if (count($parts) < 2) return $list;
    $items = preg_split("/[\r\n,]+/", $parts[1]);
    foreach ($items as $item) {
        $item = trim($item);
        if ($item !== "") $list[] = $item;
    }
    return $list;
}

/*
 * ========================
 * HANDLE AJAX ACTIONS
 * ========================
 */
if (isset($_GET['action'])) {
    header('Content-Type: application/json');
    if ($_GET['action'] == 'logout') {
        setcookie('rcon_host', '', time()-3600, "/");
        setcookie('rcon_port', '', time()-3600, "/");
        setcookie('rcon_password', '', time()-3600, "/");
        echo json_encode(['success' => true]);
        exit;
    }
    if (!isset($_COOKIE['rcon_host']) || !isset($_COOKIE['rcon_port']) || !isset($_COOKIE['rcon_password'])) {
        echo json_encode(['error' => 'Not logged in.']);
        exit;
    }
    $host     = $_COOKIE['rcon_host'];
    $port     = (int) $_COOKIE['rcon_port'];
    $password = $_COOKIE['rcon_password'];
    switch ($_GET['action']) {
        case 'command':
            $cmd = isset($_POST['cmd']) ? $_POST['cmd'] : "";
            $result = rcon_query($host, $port, $password, $cmd);
            if (!isset($_SESSION['console_log'])) {
                $_SESSION['console_log'] = [];
            }
            $_SESSION['console_log'][] = date("[H:i:s]") . " > " . $cmd;
            $_SESSION['console_log'][] = date("[H:i:s]") . " < " . $result;
            echo json_encode(['result' => $result]);
            exit;
        case 'getPlayers':
            $listResponse = rcon_query($host, $port, $password, "list");
            $players = parsePlayerList($listResponse);
            $opsResponse = rcon_query($host, $port, $password, "ops");
            $ops = parseListFromResponse($opsResponse);
            if (empty($ops)) {
                foreach ($players as &$player) {
                    $player['isOp'] = (strpos($player['name'], '§c') !== false);
                }
            } else {
                foreach ($players as &$player) {
                    $plainName = preg_replace('/§./', '', $player['name']);
                    foreach ($ops as $op) {
                        if (strcasecmp($plainName, trim($op)) === 0) {
                            $player['isOp'] = true;
                            break;
                        }
                    }
                }
            }
            if (isset($_GET['test'])) {
                $players[] = ['name' => 'TestPlayer', 'isOp' => false, 'afk' => false];
            }
            echo json_encode(['players' => $players]);
            exit;
        case 'getConsole':
            echo json_encode(['console' => isset($_SESSION['console_log']) ? $_SESSION['console_log'] : []]);
            exit;
        default:
            echo json_encode(['error' => 'Unknown action']);
            exit;
    }
}

/*
 * ========================
 * HANDLE LOGIN SUBMISSION
 * ========================
 */
if (isset($_POST['login'])) {
    setcookie('rcon_host', $_POST['host'], time() + 3600 * 24 * 30, "/");
    setcookie('rcon_port', $_POST['port'], time() + 3600 * 24 * 30, "/");
    setcookie('rcon_password', $_POST['password'], time() + 3600 * 24 * 30, "/");
    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}

/*
 * ========================
 * DISPLAY: LOGIN SCREEN OR MAIN PAGE
 * ========================
 */
if (!isset($_COOKIE['rcon_host']) || !isset($_COOKIE['rcon_port']) || !isset($_COOKIE['rcon_password'])) {
    ?>
<!DOCTYPE html>
<html>
<head>
  <meta charset="utf-8">
  <title>Minecraft RCON Client – Login</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
  <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;700&display=swap" rel="stylesheet">
  <style>
    html, body {
      height: 100%;
    }
    body {
      background: #1e1e1e;
      color: #e0e0e0;
      font-family: 'Roboto', sans-serif;
      margin: 0;
      /* Alleen links en rechts 20px, boven en onder 0 */
      padding: 0 20px;
      box-sizing: border-box;
    }
    .login-container {
      max-width: 400px;
      margin: 100px auto;
      padding: 30px;
      background: #2a2a2a;
      border-radius: 8px;
      box-shadow: 0 4px 10px rgba(0,0,0,0.6);
      box-sizing: border-box;
    }
    .login-container h2 {
      text-align: center;
      margin-bottom: 20px;
    }
    .login-container input {
      width: 100%;
      padding: 12px;
      margin: 10px 0;
      border: none;
      border-radius: 4px;
      box-sizing: border-box;
    }
    .login-container .button {
      width: 100%;
      padding: 12px;
      background: #ff8800;
      border: none;
      border-radius: 4px;
      color: #fff;
      font-weight: bold;
      cursor: pointer;
      margin-top: 10px;
      box-sizing: border-box;
    }
    .login-container .button:hover {
      background: #ffaa00;
    }
  </style>
</head>
<body>
  <div class="login-container">
    <h2>Log in to your RCON Server</h2>
    <form method="post" action="">
      <input type="text" name="host" placeholder="Server IP / Host" required>
      <input type="number" name="port" placeholder="RCON Port" value="25575" required>
      <input type="password" name="password" placeholder="RCON Password" required>
      <input type="submit" name="login" value="Log in" class="button">
    </form>
  </div>
</body>
</html>
    <?php
    exit;
}
?>

<!DOCTYPE html>
<html>
<head>
  <meta charset="utf-8">
  <title>Minecraft RCON Client</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
  <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;700&display=swap" rel="stylesheet">
  <style>
    html, body {
      height: 100%;
    }
    /* Flexbox-indeling voor de pagina zodat de console de resterende ruimte opvult */
    .container {
      display: flex;
      flex-direction: column;
      height: 100vh;
      max-width: 1200px;
      margin: 0 auto;
      padding-bottom: 30px; /* Zorgt dat de onderkant 30px hoger ligt */
      box-sizing: border-box;
    }
    body {
      background: #1e1e1e;
      color: #e0e0e0;
      font-family: 'Roboto', sans-serif;
      margin: 0;
      /* Alleen links en rechts 20px, boven en onder 0 */
      padding: 0 20px;
    }
    h1, h2 {
      text-align: center;
      margin-bottom: 10px;
    }
    .logout {
      position: absolute;
      top: 20px;
      right: 20px;
      background: #ff5555;
      color: #fff;
      border: none;
      padding: 10px 15px;
      cursor: pointer;
      border-radius: 5px;
      font-weight: bold;
    }
    /* Online Players styling: container 75% breed, max-width 500px, gecentreerd */
    #playersSection {
      margin-bottom: 20px;
    }
    #togglePlayersBtn {
      display: block;
      margin: 0 auto 10px;
      padding: 5px 10px;
      cursor: pointer;
      background: #555;
      color: #fff;
      border: none;
      border-radius: 4px;
    }
    #togglePlayersBtn:hover {
      background: #777;
    }
    #players {
      display: flex;
      flex-direction: column;
      gap: 15px;
      width: 75%;
      max-width: 500px;
      margin-left: auto;
      margin-right: auto;
    }
    .player-card {
      background: #2a2a2a;
      padding: 10px 15px;
      border-radius: 8px;
      box-shadow: 0 2px 5px rgba(0,0,0,0.5);
      display: flex;
      align-items: center;
      width: 100%;
    }
    .player-name {
      flex: 1;
      text-align: left;
      font-size: 1.1em;
      font-weight: bold;
      padding-right: 10px;
    }
    .player-actions {
      display: inline-flex;
      gap: 5px;
    }
    .player-actions button {
      padding: 5px 8px;
      border: none;
      border-radius: 4px;
      cursor: pointer;
      background: #555;
      color: #fff;
      font-size: 0.9em;
    }
    .player-actions button:hover {
      background: #777;
    }
    /* Console section vult de resterende ruimte */
    #consoleSection {
      flex-grow: 1;
      display: flex;
      flex-direction: column;
      min-height: 350px;
    }
    #console {
      flex-grow: 1;
      min-height: 300px;
      margin-bottom: 10px;
      background: #000;
      color: #fff;
      padding: 10px;
      overflow-y: auto;
      border: 1px solid #333;
      font-family: 'Courier New', monospace;
      font-size: 0.95em;
      line-height: 1.2;
      white-space: pre-wrap;
    }
    #console .console-line {
      margin: 0;
      padding: 2px 0;
    }
    /* Command input styling: command balk zonder negatieve marge */
    .command-container {
      display: inline-flex;
      align-items: center;
      gap: 10px;
      margin-top: 10px;
      width: 100%;
      justify-content: center;
      flex-shrink: 0;
      position: sticky;
      bottom: 0;
      background: inherit;
      padding: 10px 0;
    }
    #commandInput {
      flex-grow: 1;
      padding: 10px;
      border: none;
      border-radius: 4px;
      font-size: 1em;
    }
    #sendCommandBtn {
      padding: 10px 20px;
      border: none;
      border-radius: 4px;
      background: #ff8800;
      color: #fff;
      cursor: pointer;
      font-size: 1em;
    }
    #sendCommandBtn:hover {
      background: #ffaa00;
    }
    
    /* Responsive styling voor smartphones */
    @media (max-width: 600px) {
      #players {
        width: 100%;
        max-width: 100%;
      }
      .player-card {
        padding: 8px 10px;
      }
      #console {
        font-size: 0.85em;
      }
      .container {
        padding: 10px;
      }
    }
  </style>
</head>
<body>
  <button class="logout" id="logoutBtn">Log out</button>
  <div class="container">
    <h1>Minecraft RCON Client</h1>
    <!-- Toon de verbonden server -->
    <p style="text-align:center;">Connected to <?php echo htmlspecialchars($_COOKIE['rcon_host']); ?></p>
    
    <!-- Online Players -->
    <section id="playersSection">
      <h2>Online Players</h2>
      <button id="togglePlayersBtn">Toggle</button>
      <div id="players"></div>
    </section>
    
    <!-- Console and Command Input -->
    <section id="consoleSection">
      <h2>Console Output</h2>
      <div id="console"></div>
      <div class="command-container">
        <input type="text" id="commandInput" placeholder="Enter command..." />
        <button id="sendCommandBtn">Send</button>
      </div>
    </section>
  </div>
  
  <script>
    // Logout
    document.getElementById('logoutBtn').addEventListener('click', function(){
      fetch('?action=logout').then(function(){ location.reload(); });
    });
    
    // Toggle Online Players List
    document.getElementById('togglePlayersBtn').addEventListener('click', function(){
      let playersDiv = document.getElementById('players');
      if (playersDiv.style.display === 'none') {
        playersDiv.style.display = 'flex';
      } else {
        playersDiv.style.display = 'none';
      }
    });
    
    // Fetch online players
    function fetchPlayers() {
      fetch('?action=getPlayers' + (window.location.search.indexOf("test") !== -1 ? "&test=1" : ""))
        .then(response => response.json())
        .then(function(data) {
          let playersDiv = document.getElementById('players');
          playersDiv.innerHTML = '';
          if (data.players) {
            data.players.forEach(function(player) {
              let card = document.createElement('div');
              card.className = 'player-card';
              
              let nameDiv = document.createElement('div');
              nameDiv.className = 'player-name';
              // Verwijder "[AFK]" voor de bold; voeg dit daarna als gewone witte tekst toe indien afwezig.
              let nameForBold = player.name.replace(/\[AFK\]/i, "").trim();
              let displayName = "<b>" + convertMinecraftColors(nameForBold) + "</b>";
              if (player.afk) {
                displayName += " <span style='color:#fff;'>[AFK]</span>";
              }
              nameDiv.innerHTML = displayName;
              card.appendChild(nameDiv);
              
              let actionsDiv = document.createElement('div');
              actionsDiv.className = 'player-actions';
              
              // Kick button met reden
              let kickBtn = document.createElement('button');
              kickBtn.textContent = 'Kick';
              kickBtn.onclick = function(){
                let reason = prompt("Enter reason for kick:");
                if (reason !== null && reason.trim() !== "") {
                  sendCommand('kick ' + stripColorCodes(player.name).trim() + ' ' + reason);
                }
              };
              actionsDiv.appendChild(kickBtn);
              
              // Ban button met reden
              let banBtn = document.createElement('button');
              banBtn.textContent = 'Ban';
              banBtn.onclick = function(){
                let reason = prompt("Enter reason for ban:");
                if (reason !== null && reason.trim() !== "") {
                  sendCommand('ban ' + stripColorCodes(player.name).trim() + ' ' + reason);
                }
              };
              actionsDiv.appendChild(banBtn);
              
              card.appendChild(actionsDiv);
              playersDiv.appendChild(card);
            });
          }
        });
    }
    
    // Fetch console output met timestamp per regel
    function fetchConsole() {
      fetch('?action=getConsole')
        .then(response => response.json())
        .then(function(data) {
          let consoleDiv = document.getElementById('console');
          consoleDiv.innerHTML = '';
          if (data.console) {
            data.console.forEach(function(entry) {
              let lines = entry.split(/\r?\n/);
              let timestampMatch = lines[0].match(/^\[\d{2}:\d{2}:\d{2}\]/);
              let timestamp = timestampMatch ? timestampMatch[0] : "";
              lines.forEach(function(line, index) {
                if(line !== "") {
                  let displayLine = (index === 0) ? line : timestamp + " " + line;
                  if(index > 0 && !line.trim().startsWith("<") && !line.trim().startsWith(">")) {
                    displayLine = timestamp + " < " + line;
                  }
                  let lineDiv = document.createElement('div');
                  lineDiv.className = 'console-line';
                  lineDiv.innerHTML = convertMinecraftColors(displayLine);
                  consoleDiv.appendChild(lineDiv);
                }
              });
            });
            consoleDiv.scrollTop = consoleDiv.scrollHeight;
          }
        });
    }
    
    // Send a command
    function sendCommand(cmd) {
      fetch('?action=command', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'cmd=' + encodeURIComponent(cmd)
      })
      .then(response => response.json())
      .then(function(data) {
        fetchConsole();
        fetchPlayers();
      });
    }
    
    // Bind command input events
    document.getElementById('sendCommandBtn').addEventListener('click', function(){
      let cmdInput = document.getElementById('commandInput');
      if (cmdInput.value.trim() !== "") {
        sendCommand(cmdInput.value);
        cmdInput.value = "";
      }
    });
    document.getElementById('commandInput').addEventListener('keydown', function(e){
      if (e.key === "Enter") {
        document.getElementById('sendCommandBtn').click();
      }
    });
    
    // Utility: convert Minecraft color codes to HTML spans (behoudt newlines)
    function convertMinecraftColors(text) {
      const colorMap = {
        '0': '#000000',
        '1': '#0000AA',
        '2': '#00AA00',
        '3': '#00AAAA',
        '4': '#AA0000',
        '5': '#AA00AA',
        '6': '#FFAA00',
        '7': '#AAAAAA',
        '8': '#555555',
        '9': '#5555FF',
        'a': '#55FF55',
        'b': '#55FFFF',
        'c': '#FF5555',
        'd': '#FF55FF',
        'e': '#FFFF55',
        'f': '#FFFFFF',
        'r': '#FFFFFF'
      };
      let result = '<span style="color:#fff;">';
      let segments = text.split("§");
      result += segments[0];
      for (let i = 1; i < segments.length; i++) {
        let code = segments[i].charAt(0).toLowerCase();
        let color = colorMap[code] || "#fff";
        result += '</span><span style="color:' + color + ';">' + segments[i].substring(1);
      }
      result += '</span>';
      return result;
    }
    
    // Utility: remove Minecraft color codes
    function stripColorCodes(text) {
      return text.replace(/§./g, '');
    }
    
    // Periodiek verversen
    setInterval(fetchPlayers, 5000);
    setInterval(fetchConsole, 3000);
    fetchPlayers();
    fetchConsole();
  </script>
</body>
</html>
