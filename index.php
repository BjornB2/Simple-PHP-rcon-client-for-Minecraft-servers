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
 * Parses a list (e.g. for ops) by splitting on commas, CR and LF.
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
      margin-bottom: 0px;
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
  <!-- Meta-tags voor web app mode op iOS -->
  <meta name="apple-mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
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
      padding-top: 40px; /* ruimte bovenaan zodat de logout knop niet over de titel valt */
      padding-bottom: 30px;
      box-sizing: border-box;
    }
    body {
      background: #1e1e1e;
      color: #e0e0e0;
      font-family: 'Roboto', sans-serif;
      margin: 0;
      padding: 0 10px;
    }
    h1, h2 {
      text-align: center;
      margin-bottom: 10px;
      margin-top: 5px;
    }
    /* Zorg dat het connected-to gedeelte gecentreerd is met 10px marge */
    .connected {
      text-align: center;
      margin: 10px 0;
    }
    /* Op mobiel: halveer de ruimte tussen de elementen */
    @media (max-width: 600px) {
      h1 {
        margin-top: 60px;
        margin-bottom: 5px;
      }
      .connected {
        margin-top: 5px;
        margin-bottom: 5px;
      }
      #playersSection {
        margin-top: 5px;
        margin-bottom: 5px;
      }
      #consoleSection {
        margin-top: 5px;
      }
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
    /* Online Players styling */
    #playersSection {
      margin-bottom: 10px;
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
      gap: 5px;
      width: 75%;
      max-width: 500px;
      margin: 0 auto;
      box-sizing: border-box;
    }
    .player-card {
      background: #2a2a2a;
      padding: 8px 10px;
      border-radius: 8px;
      box-shadow: 0 2px 5px rgba(0,0,0,0.5);
      display: flex;
      align-items: center;
      width: 100%;
      box-sizing: border-box;
    }
    .player-name {
      flex: 1;
      text-align: left;
      font-size: 1.1em;
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
      min-height: 150px;
      position: relative;
    }
    .console-container {
      position: relative;
      flex-grow: 1;
      margin-top: 10px; /* 10px extra marge bovenaan */
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
    /* Command input styling */
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
    
    /* Extra CSS voor web app mode (standalone) */
    body.standalone .command-container {
        margin-bottom: 40px;
    }
    body.standalone .logout {
        top: env(safe-area-inset-top);
    }
    body.standalone h1 {
      margin-top: calc(40px + env(safe-area-inset-top));
    }
    body.standalone #consoleSection.fullscreen {
      /* Pas de bovenruimte aan: 20px extra plus de safe-area inset als die er is */
      padding-top: calc(20px + env(safe-area-inset-top, 0px));
    }

    /* ===== Fullscreen toggle knop en fullscreen styling ===== */
    /* De knop staat nu direct in #consoleSection (buiten de scrollbare .console-container) */
    #fullscreenToggleBtn {
      position: absolute;
      top: 20px;
      right: 25px;
      background: #ff8800;
      color: #fff;
      border: none;
      border-radius: 4px;
      padding: 8px 12px;
      cursor: pointer;
      z-index: 1100;
    }

    #fullscreenToggleBtn:hover {
      background: #ffaa00;
    }
    #consoleSection.fullscreen #fullscreenToggleBtn {
      top: calc(40px + env(safe-area-inset-top, 0px));
      right: 45px;
    }
    /* Fullscreen-styling voor de consoleSection */
    #consoleSection.fullscreen {
      position: fixed;
      top: 0;
      left: 0;
      width: 100vw;
      height: 100vh;
      background: #1e1e1e;
      z-index: 1000;
      padding: 20px;
      box-sizing: border-box;
    }

    /* Responsive styling voor smartphones */
    @media (max-width: 600px) {
      #players {
        width: 100%;
        max-width: 100%;
      }
      .player-card {
        padding: 6px 8px;
      }
      #console {
        font-size: 0.85em;
        min-height: 100px;
      }
      .container {
        padding: 10px;
      }
      #fullscreenToggleBtn {
        right: 10px;
      }
      #consoleSection.fullscreen #fullscreenToggleBtn {
        top: calc(40px + env(safe-area-inset-top, 0px));
        right: 30px;
      }
    }
    /* Add-to-Home-Screen Banner styling */
    #addToHomeScreenBanner {
      display: none; /* standaard verborgen */
      position: fixed;
      bottom: calc(20px + env(safe-area-inset-bottom, 0px)); /* houdt rekening met de safe area onderin */
      left: 50%;
      transform: translateX(-50%);
      background-color: #FFB347; /* lichtere oranje tint */
      color: #fff;
      padding: 15px 20px;
      font-size: 16px;
      text-align: center;
      border-radius: 8px;
      z-index: 3000;
      max-width: 90%;
    }

    #addToHomeScreenBanner .arrow {
      width: 0;
      height: 0;
      border-left: 10px solid transparent;
      border-right: 10px solid transparent;
      border-top: 10px solid #FFB347;
      margin: 10px auto 0;
    }

    #addToHomeScreenBanner button {
      background: transparent;
      border: none;
      color: #fff;
      font-weight: bold;
      margin-top: 10px;
      cursor: pointer;
    }

  </style>
  <script>
    // Als web app (standalone) op iOS: voeg de class 'standalone' toe aan de body
    if (window.navigator.standalone) {
      document.addEventListener("DOMContentLoaded", function() {
        document.body.classList.add("standalone");
      });
    }
  </script>
</head>
<body>
  <button class="logout" id="logoutBtn">Log out</button>
  <div class="container">
    <h1>Minecraft RCON Client</h1>
    <!-- Toon de verbonden server -->
    <p class="connected">Connected to <?php echo htmlspecialchars($_COOKIE['rcon_host']); ?></p>
    
    <!-- Online Players -->
    <section id="playersSection">
      <h2>Online Players</h2>
      <button id="togglePlayersBtn">Toggle</button>
      <div id="players"></div>
    </section>
    
    <!-- Console and Command Input -->
    <section id="consoleSection">
      <!-- Fullscreen-knop staat nu direct hier, zodat deze altijd zichtbaar blijft -->
      <button id="fullscreenToggleBtn">Fullscreen</button>
      <div class="console-container" id="consoleContainer">
        <div id="console"></div>
      </div>
      <div class="command-container">
        <input type="text" id="commandInput" placeholder="Enter command..." />
        <button id="sendCommandBtn">Send</button>
      </div>
    </section>
  </div>
  <!-- Add-to-Home-Screen Banner -->
  <div id="addToHomeScreenBanner">
    <p>To add this site to your Home Screen as an App, tap the share icon at the bottom and select "Add to Home Screen".</p>
    <div class="arrow"></div>
    <button id="dismissBanner">Close</button>
  </div>

  <script>
    // Logout knop
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
              let nameForBold = player.name.replace(/\[AFK\]/i, "").trim();
              let displayName = "<b>" + convertMinecraftColors(nameForBold) + "</b>";
              if (player.afk) {
                displayName += " <span style='color:#888;'>[AFK]</span>";
              }
              nameDiv.innerHTML = displayName;
              card.appendChild(nameDiv);
              
              let actionsDiv = document.createElement('div');
              actionsDiv.className = 'player-actions';
              
              // Kick knop met reden
              let kickBtn = document.createElement('button');
              kickBtn.textContent = 'Kick';
              kickBtn.onclick = function(){
                let reason = prompt("Enter reason for kick:");
                if (reason !== null && reason.trim() !== "") {
                  sendCommand('kick ' + stripColorCodes(player.name).trim() + ' ' + reason);
                }
              };
              actionsDiv.appendChild(kickBtn);
              
              // Ban knop met reden
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
    
    // Fetch console output met timestamp per regel en zorg voor auto-scroll
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
            // Scroll de console-container naar beneden
            let container = document.getElementById('consoleContainer');
            container.scrollTop = container.scrollHeight;
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
    
    // Fullscreen toggle knop functionaliteit
    document.getElementById('fullscreenToggleBtn').addEventListener('click', function() {
      let consoleSection = document.getElementById('consoleSection');
      if (consoleSection.classList.contains('fullscreen')) {
        // Verlaat fullscreen
        consoleSection.classList.remove('fullscreen');
        this.textContent = 'Fullscreen';
      } else {
        // Ga naar fullscreen
        consoleSection.classList.add('fullscreen');
        this.textContent = 'Exit Fullscreen';
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
    document.addEventListener("DOMContentLoaded", function() {
      var isIos = /iphone|ipad|ipod/i.test(window.navigator.userAgent);
      var isInStandaloneMode = ('standalone' in window.navigator) && window.navigator.standalone;
      
      // Toon de banner als het een iOS-apparaat is, niet in standalone mode én de banner nog niet is gesloten
      if (isIos && !isInStandaloneMode && !localStorage.getItem("addToHomeScreenDismissed")) {
        setTimeout(function() {
          document.getElementById("addToHomeScreenBanner").style.display = "block";
        }, 5000);
      }
      
      // Wanneer de gebruiker op "Close" klikt, verberg de banner en onthoud de keuze
      document.getElementById("dismissBanner").addEventListener("click", function() {
        document.getElementById("addToHomeScreenBanner").style.display = "none";
        localStorage.setItem("addToHomeScreenDismissed", "true");
      });
    });

  </script>
</body>
</html>
