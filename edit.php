<div id="edit">
    <div class="sidebar">
        <h2>Dateiliste</h2>
        <button id="menutoggle" class="dropdown-toggle filemenu">File auswählen</button>
        <ul class="file-list" id="file-list">
            <li>🗎 New Item</li>
        </ul>
    </div>
    <div class="editor-container">
        <!-- EDIT --->
        <div id="editContainer">
            <div class="form-group">
                <input type="hidden" name="e_uid" id="e_uid" value="" class="mapdata">
                <input type=" text" id="e_title" name="e_title" class="input-big mapdata" placeholder="Title" style="margin-bottom: 0.5em; font-weight:bold;font-size:1.2em;" value="">
                <div id="extdata">
                    <div>Created:</div>
                    <div id="f_created"></div>
                    <div>Changed:</div>
                    <div id="f_changed"></div>
                    <div>
                        <div class="share"></div>
                    </div>
                </div>
                <div id="e_data" class="mapdata">
                </div>
                <button type="button" class="form-button" id="btsave">Save</button>
                <button type="button" class="form-button" id="btdelete">Delete</button>
            </div>
        </div>

        <!-- SHARE --->
        <div id="shareContainer" style="display:none">
            <div id="share" style="max-width: 650px;">
                <h2>Share Store</h2>
                <p>
                    Du kannst den Store und seinen Inhalt mit einer oder mehreren Personen teilen, die im Ernstfall Zugriff auf diesen erlangen können.
                </p>
                <p>Dies ist gedacht um zum Beispiel wichtige Zugangsdaten und im Todesfall mit engen Freunden oder Verwandten zu teilen.</p>

                <div class=" form-group form-big">
                    <label for="userID">Geben Sie hier zur Bestätigung die Store ID an:</label>
                    <input type="text" id="userId" name="userId" placeholder="Enter Store ID">
                </div>
                <label for="seed">Seed Vorschlag, kann auch abgeändert werden:<br><i>Derjenige der auf den Store Zugriff erlangen möchte muss den Seed kennen</i></label>

                <div class="form-group form-big" style="display: grid; grid-template-columns: 1fr 1fr 1fr;">
                    <div class="seed"><input type="text" id="seed_1" name="seed_1" value=""></div>
                    <div class="seed"><input type="text" id="seed_2" name="seed_2" value=""></div>
                    <div class="seed"><input type="text" id="seed_3" name="seed_3" value=""></div>
                    <div class="seed"><input type="text" id="seed_4" name="seed_4" value=""></div>
                    <div class="seed"><input type="text" id="seed_5" name="seed_5" value=""></div>
                    <div class="seed"><input type="text" id="seed_6" name="seed_6" value=""></div>
                    <div class="seed"><input type="text" id="seed_7" name="seed_7" value=""></div>
                    <div class="seed"><input type="text" id="seed_8" name="seed_8" value=""></div>
                    <div class="seed"><input type="text" id="seed_9" name="seed_9" value=""></div>
                    <div class="seed"><input type="text" id="seed_10" name="seed_10" value=""></div>
                    <div class="seed"><input type="text" id="seed_11" name="seed_11" value=""></div>
                    <div class="seed"><input type="text" id="seed_12" name="seed_12" value=""></div>
                </div>
                <div class=" form-group form-big">
                    <p>Du als Inhaber haben die Möglichkeit die Zugriffsanfrage innerhalb einer bestimmten Zeitdauer abzulehnen. Hier bitte Deine Email-Adresse eintragen und die Verzögerungsdauer in Stunden.</p>
                    <label for="f_email">E-Mail für verzögerten Zugriff</label>
                    <input type="text" id="f_email" name="f_email" placeholder="E-Mail Adresse">
                </div>
                <div class=" form-group form-big">
                    <label for="f_timeout">Stunden so lange Du Zeit hast die Anfrage abzulehnen.</label>
                    <input type="text" id="f_duration" name="f_duration" placeholder="Stunden">
                </div>

                <div class="form-group form-big">
                    <button type="button" class="login-button" id="btcreateshare">Share Einrichten</button>
                </div>

            </div>
        </div>

    </div>
    <div></div>
</div>

<script>
    const toolbarOptions = [
        [{
            'size': ['small', false, 'large', 'huge']
        }], // custom dropdown
        [{
            'font': []
        }],
        ['bold', 'italic', 'underline'], // toggled buttons
        [{
            'color': []
        }], // dropdown with defaults from theme
        ['blockquote', 'code-block'],
        [{
            'align': []
        }],
        [{
            'indent': '-1'
        }, {
            'indent': '+1'
        }], // outdent/indent
        [{
            'list': 'ordered'
        }, {
            'list': 'bullet'
        }, {
            'list': 'check'
        }],

        ['link'],
        ['clean'] // remove formatting button
    ];
    const quill = new Quill('#e_data', {
        modules: {
            toolbar: toolbarOptions,
            table: true
        },
        theme: 'snow'
    });

    $("#btsave").click(function() {
        eC.save();
    });

    $("#btdelete").click(function() {
        if (confirm("Wirklich löschen?")) {
            eC.delete();
        }
    });

    eC.loadItems();

    $('#btlogout').click(function() {
        localStorage.setItem("v", "");
        localStorage.setItem("i", "");
        localStorage.setItem("key", "");
        localStorage.setItem("userId", "");
        document.location.reload();
    });

    $('#menutoggle').click(function() {
        document.querySelector('.file-list').classList.toggle('show-file-list');
    });
    $(".statuslinks").show();
    $('#btshare').click(function() {
        $('#editContainer').hide();
        $("#shareContainer").show();
        m_share.fillSeed();
    });
    $("#btcreateshare").click(function() {
        m_share.createShare();
    });
</script>