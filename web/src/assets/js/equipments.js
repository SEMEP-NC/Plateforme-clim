
        const equipModalEl = document.getElementById("commandModal");
        const equipModal = (equipModalEl && typeof bootstrap !== "undefined")
            ? bootstrap.Modal.getOrCreateInstance(equipModalEl)
            : null;


        const groupModalEl = document.getElementById("groupCommandModal");
        const groupModal = (groupModalEl && typeof bootstrap !== "undefined")
            ? bootstrap.Modal.getOrCreateInstance(groupModalEl)
            : null;
        

        let lastReadRegisters = [];
        let currentEquipmentId = null;
        let currentGroupId = null;

        function setChecked(id, value) {
            const el = document.getElementById(id);
            if (el) {
                el.checked = !!value;
            }
        }


        function setValue(id, value) {
            const el = document.getElementById(id);
            if (el) {
                el.value = value;
            }
        }

        function isChecked(id){
            return document.getElementById(id)?.checked ?? false;
        }
        document.querySelectorAll('.group-localisation-filter')
            .forEach(select => {
                select.addEventListener('change', function(){
                    const localisation = this.value;
                    const groupId = this.dataset.group;
                    document
                        .querySelectorAll(
                            '.group-equipment-list[data-group="' + groupId + '"] .group-equipment-item'
                        )
                        .forEach(item => {
                            const itemLoc = item.dataset.localisation;
                            if(localisation === "" || itemLoc === localisation){
                                item.style.display = "";
                            }
                            else{
                                item.style.display = "none";
                            }
                        });
                });
            });

        /* =========================
        EQUIPMENT : OPEN + READ
        ========================= */
        document.querySelectorAll(".commandButton").forEach(button => {
            button.addEventListener("click", async () => {

                const id = button.dataset.id;
                currentEquipmentId = id;

                setValue("equipment_id", id);

                try {
                    const res = await fetch(`/api/modbus_proxy.php?id=${id}`);
                    const data = await res.json();

                    if (!data.success) {
                        throw new Error(data.error || "Modbus read error");
                    }

                    const regs = Array.isArray(data.registers) ? data.registers : [];
                    const shields = Array.isArray(data.coils) ? data.coils : [];
                    // reset checkboxes
                    document.querySelectorAll("#commandForm input[type=checkbox]")
                        .forEach(c => c.checked = false);

                    setChecked("shield_energy", shields[0]);
                    setChecked("shield_setpoint", shields[1]);
                    setChecked("shield_mode", shields[2]);
                    setChecked("shield_power", shields[3]);
                    setChecked("lock_function", shields[4]);

                    lastReadRegisters = regs.map(v =>
                        (v === null || v === undefined || isNaN(v)) ? 0 : Number(v)
                    );

                    while (lastReadRegisters.length < 5) {
                        lastReadRegisters.push(0);
                    }

                    // UI
                    setValue("power", lastReadRegisters[0]);
                    setValue("mode", lastReadRegisters[1]);
                    setValue("setpoint", lastReadRegisters[2] / 10 );
                    setValue("fan", lastReadRegisters[3]);
                    setValue("min_setpoint", lastReadRegisters[4] / 10);

                    

                    if (equipModal) {
                        equipModal.show();
                    }

                } catch (e) {
                    console.error(e);
                    alert("Erreur lecture équipement");
                }
            });
        });


        /* =========================
        EQUIPMENT : WRITE
        ========================= */
        const commandForm = document.getElementById("commandForm");

        if (commandForm) {
            commandForm.addEventListener("submit", async (e) => {
                e.preventDefault();

                const id = currentEquipmentId ||
                        document.getElementById("equipment_id")?.value;

                if (!id) {
                    alert("ID équipement manquant");
                    return;
                }

                const regs = [...lastReadRegisters];

                while (regs.length < 5) {
                    regs.push(0);
                }

                if (document.querySelector('[name="send_power"]')?.checked) {
                    regs[0] = parseInt(
                        document.getElementById("power").value
                    ) || 0;
                }

                if (document.querySelector('[name="send_mode"]')?.checked) {
                    regs[1] = parseInt(
                        document.getElementById("mode").value
                    ) || 0;
                }

                if (document.querySelector('[name="send_setpoint"]')?.checked) {
                    const sp = parseFloat(
                        document.getElementById("setpoint").value
                    );
                    regs[2] = isNaN(sp)
                        ? 0
                        : Math.round(sp * 10);
                }

                if (document.querySelector('[name="send_fan"]')?.checked) {
                    regs[3] = parseInt(
                        document.getElementById("fan").value
                    ) || 0;
                }

                if (document.querySelector('[name="send_min_setpoint"]')?.checked) {
                    const minSp = parseFloat(
                        document.getElementById("min_setpoint").value
                    );
                    regs[4] = isNaN(minSp)
                        ? 0
                        : Math.round(minSp * 10);
                }

                const shields = [
                    document.getElementById("shield_energy")?.checked ?? false,
                    document.getElementById("shield_setpoint")?.checked ?? false,
                    document.getElementById("shield_mode")?.checked ?? false,
                    document.getElementById("shield_power")?.checked ?? false,
                    document.getElementById("lock_function")?.checked ?? false
                ];

                try {
                    const res = await fetch(
                        `/api/modbus_proxy.php?action=write&id=${id}`,
                        {
                            method:"POST",
                            headers:{
                                "Content-Type":"application/json"
                            },
                            body:JSON.stringify({
                                registers:regs,
                                shields:shields
                            })
                        }
                    );

                    const data = await res.json();

                    if(!data.success) {
                        throw new Error(data.error);
                    }

                    alert("Commande envoyée");
                    equipModal?.hide();
                } catch(err){

                    console.error(err);
                    alert("Erreur écriture Modbus");
                }
            });
        }

        /* =========================
        GROUP : OPEN MODAL
        ========================= */
        document.querySelectorAll(".groupCommandButton").forEach(btn => {
            btn.addEventListener("click", () => {

                currentGroupId = btn.dataset.id;
                setValue("group_id", currentGroupId);

                // RESET CHECKBOX GROUP
                document.querySelectorAll("#groupCommandForm input[type=checkbox]")
                    .forEach(c => c.checked = false);

                document.querySelectorAll("#groupCommandForm select")
                    .forEach(s => s.value = "");

                document.querySelectorAll("#groupCommandForm input[type=number]")
                    .forEach(i => i.value = "");
                // RESET INPUTS OPTIONNELS
                setValue("g_setpoint", "24");
                setValue("g_min_setpoint", "24");
                if (groupModal) {
                    groupModal.show();
                }
            });
        });

        /* =========================
        GROUP : RESET MODAL
        ========================= */
        const groupCommandModalEl = document.getElementById("groupCommandModal");

        if (groupCommandModalEl) {

            groupCommandModalEl.addEventListener("show.bs.modal", () => {

                const shieldCollapse = document.getElementById("shieldCollapse");

                if (shieldCollapse) {
                    bootstrap.Collapse.getOrCreateInstance(shieldCollapse, {
                        toggle: false
                    }).hide();
                }

            });

        }
        /* =========================
        GROUP : WRITE (BROADCAST)
        ========================= */
        const groupCommandForm = document.getElementById("groupCommandForm");


        if(groupCommandForm){

            groupCommandForm.addEventListener("submit", async(e)=>{

                e.preventDefault();

                if (!currentGroupId) {
                    alert("Groupe manquant");
                    return;
                }

                const registers = {};

                if (isChecked("send_power_group")) {
                    const v = document.getElementById("g_power").value;
                    if (v !== "") registers.power = parseInt(v);
                }

                if (isChecked("send_mode_group")) {
                    const v = document.getElementById("g_mode").value;
                    if (v !== "") registers.mode = parseInt(v);
                }

                if (isChecked("send_setpoint_group")) {
                    const v = document.getElementById("g_setpoint").value;
                    if (v !== "") registers.setpoint = Math.round(parseFloat(v));
                }

                if (isChecked("send_fan_group")) {
                    const v = document.getElementById("g_fan").value;
                    if (v !== "") registers.fan = parseInt(v);
                }
                if (isChecked("send_min_setpoint_group")) {
                    const v = document.getElementById("g_min_setpoint").value;
                    if (v !== "") registers.min_setpoint = Math.round(parseFloat(v));
                }

                const payload = {
                    group_id: currentGroupId,
                    registers: registers
                };

                const shieldCollapse = document.getElementById("shieldCollapse");

                if (shieldCollapse && shieldCollapse.classList.contains("show")) {
                    payload.shields = [
                        document.getElementById("g_shield_energy").checked,
                        document.getElementById("g_shield_setpoint").checked,
                        document.getElementById("g_shield_mode").checked,
                        document.getElementById("g_shield_power").checked,
                        document.getElementById("g_lock_function").checked
                    ];
                }
                
                try {
                    const res = await fetch("/api/modbus_group_proxy.php?action=write_group", {
                        method: "POST",
                        headers: { "Content-Type": "application/json" },
                        body: JSON.stringify(payload)
                    });

                    const text = await res.text();

                    let data;
                    try {
                        data = JSON.parse(text);
                    } catch (e) {
                        console.error("RAW RESPONSE:", text);
                        throw new Error("Invalid JSON from server");
                    }

                    if (!data.success) throw new Error(data.error || "Group write failed");

                    alert("Commande groupe envoyée");
                    groupModal.hide();

                } catch (err) {
                    console.error(err);
                    alert("Erreur commande groupe");
                }
            });
        }

        //script modal courbe
        let historyChart = null;

        function toUTCPlus11(dateStr) {
            // équipement DB en UTC -> conversion locale +11
            const d = new Date(dateStr);
            return new Date(d.getTime() + 11 * 60 * 60 * 1000);
        }

        document.querySelectorAll(".historyButton").forEach(button => {
            button.addEventListener("click", async function () {

                try {
                    const id = this.dataset.id;
                    const name = this.dataset.name;

                    document.getElementById("historyTitle").textContent = name;

                    const response = await fetch("equipment_history.php?id=" + id);

                    if (!response.ok) {
                        throw new Error("HTTP " + response.status);
                    }

                    const data = await response.json();

                    if (!Array.isArray(data)) {
                        console.error("API invalid:", data);
                        return;
                    }

                    const labels = data.map(p => toUTCPlus11(p.created_at));

                    const retour = data.map(p => p.return_temp);
                    const consigne = data.map(p => p.setpoint);
                    const ext = data.map(p => p.outside_temp);

                    // ON/OFF (0/10 demandé)
                    const state = data.map(p => (p.state ? 10 : 0));

                    const modalEl = document.getElementById("historyModal");
                    const canvas = document.getElementById("historyChart");

                    if (!modalEl || typeof bootstrap === "undefined") {
                        return;
                    }

                    const modal =
                        bootstrap.Modal.getOrCreateInstance(modalEl);

                    modal.show();

                    modalEl.addEventListener("shown.bs.modal", function handler() {

                        modalEl.removeEventListener("shown.bs.modal", handler);

                        if (historyChart) {
                            historyChart.destroy();
                        }

                        if(typeof Chart === "undefined"){
                            console.error("Chart.js non chargé");
                            return;
                        }
                        const ctx = canvas.getContext("2d");


                        historyChart = new Chart(ctx, {
                            type: "line",
                            data: {
                                labels,
                                datasets: [
                                    {
                                        label: "T°C Ambiance",
                                        data: retour,
                                        borderColor: "#0d6efd",
                                        tension: 0.35,
                                        pointRadius: 0,
                                        yAxisID: "y"
                                    },
                                    {
                                        label: "T°C Consigne",
                                        data: consigne,
                                        borderColor: "#198754",
                                        tension: 0.35,
                                        pointRadius: 0,
                                        yAxisID: "y"
                                    },
                                    {
                                        label: "T°C Exterieur",
                                        data: ext,
                                        borderColor: "#fd7e14",
                                        tension: 0.35,
                                        pointRadius: 0,
                                        yAxisID: "y"
                                    },
                                    {
                                        label: "ON/OFF",
                                        data: state,
                                        borderColor: "#dc3545",
                                        stepped: true,
                                        pointRadius: 0,
                                        yAxisID: "yState"
                                    }
                                ]
                            },
                            options: {
                                responsive: true,
                                maintainAspectRatio: false,

                                interaction: {
                                    mode: "index",
                                    intersect: false
                                },

                                plugins: {
                                    legend: { position: "top" },

                                    zoom: {
                                        pan: {
                                            enabled: true,
                                            mode: "x"
                                        },
                                        zoom: {
                                            wheel: { enabled: true },
                                            pinch: { enabled: true },
                                            mode: "x"
                                        }
                                    }
                                },

                                scales: {
                                    x: {
                                        type: "time",
                                        time: {
                                            tooltipFormat: "dd/MM/yyyy HH:mm",
                                            displayFormats: {
                                                minute: "HH:mm",
                                                hour: "dd/MM HH:mm"
                                            }
                                        },
                                        ticks: {
                                            source: "auto"
                                        }
                                    },

                                    y: {
                                        min: 0,
                                        max: 35,
                                        title: {
                                            display: true,
                                            text: "Température (°C)"
                                        }
                                    },

                                    yState: {
                                        position: "right",
                                        min: 0,
                                        max: 10,
                                        ticks: {
                                            stepSize: 10,
                                            callback: v => v === 10 ? "ON" : "OFF"
                                        },
                                        grid: {
                                            drawOnChartArea: false
                                        }
                                    }
                                }
                            }
                        });
                    }, { once: true });

                } catch (err) {
                    console.error("ERROR:", err);
                }
            });
        });
        /* =========================
        TRI TABLE UNITÉS
        ========================= */

        const sortDirections = {};

        document.querySelectorAll("#equipmentsTable th.sortable").forEach(th => {

            th.addEventListener("click", function () {

                const table = document.getElementById("equipmentsTable");
                if(!table){
                    return;
                }

                const tbody = table.querySelector("tbody");
                if(!tbody){
                    return;
                }

                const key = this.dataset.sort;

                // position réelle de la colonne cliquée
                const colIndex = Array.from(
                    this.parentElement.children
                ).indexOf(this);

                sortDirections[key] = !sortDirections[key];


                const rows = Array.from(tbody.querySelectorAll("tr"));

                rows.sort((a, b) => {

                    let cellA = a.cells[colIndex];
                    let cellB = b.cells[colIndex];

                    let valA = "";
                    let valB = "";


                    // Cas Nom avec input admin
                    if(!cellA || !cellB){
                        return 0;
                    }
                    const inputA = cellA.querySelector("input");
                    const inputB = cellB.querySelector("input");

                    if (inputA) {
                        valA = inputA.value;
                    } else {
                        valA = cellA.innerText;
                    }

                    if (inputB) {
                        valB = inputB.value;
                    } else {
                        valB = cellB.innerText;
                    }


                    valA = valA.trim();
                    valB = valB.trim();


                    // Colonnes numériques
                    if (["ui", "temp"].includes(key)) {

                        let numA = parseFloat(valA.replace(",", "."));
                        let numB = parseFloat(valB.replace(",", "."));

                        numA = isNaN(numA) ? -9999 : numA;
                        numB = isNaN(numB) ? -9999 : numB;

                        return sortDirections[key]
                            ? numB - numA
                            : numA - numB;
                    }


                    return sortDirections[key]
                        ? valB.localeCompare(valA, "fr")
                        : valA.localeCompare(valB, "fr");

                });


                rows.forEach(row => tbody.appendChild(row));


                // Mise à jour icônes
                document.querySelectorAll("#equipmentsTable th.sortable span")
                    .forEach(span => span.textContent = "↕");

                this.querySelector("span").textContent =
                    sortDirections[key] ? "↓" : "↑";

            });

        });
        /* =========================
        FILTRE TABLE UNITÉS
        ========================= */
        document.querySelectorAll(".localisation-filter").forEach(cb => {
            cb.addEventListener("change", function () {
                const selected = Array.from(
                    document.querySelectorAll(".localisation-filter:checked")
                )
                .map(cb => cb.value.toLowerCase());

                document
                    .querySelectorAll("#equipmentsTable tbody tr")
                    .forEach(row => {
                        const cell = row.cells[0];
                        if(!cell){
                            return;
                        }
                        const localisation =
                            cell.dataset.localisation?.toLowerCase() || "";

                        if (
                            selected.length === 0 ||
                            selected.includes(localisation)
                        ) {
                            row.style.display = "";
                        } else {
                            row.style.display = "none";
                        }
                    });
            });
        });

        document.querySelectorAll('.group-filter')
        .forEach(filter => {
            filter.addEventListener('change', function(){
                const selectedGroup = this.value;
                document
                .querySelectorAll('#equipmentsTable tbody tr')
                .forEach(row => {
                    if(selectedGroup === ""){
                        row.style.display = "";
                    }
                    else {
                        const groups = row.dataset.groups
                            ? row.dataset.groups.split(',')
                            : [];
                        if(groups.includes(selectedGroup)){
                            row.style.display = "";
                        }
                        else{
                            row.style.display = "none";
                        }
                    }
                });
            });
        });
