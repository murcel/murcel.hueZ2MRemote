<?php
declare(strict_types=1);

class HueZ2MRemote extends IPSModule
{
    public function Create()
    {
        parent::Create();

        // Basis-Properties
        $this->RegisterPropertyInteger('ActionVarId', 0);
        $this->RegisterPropertyInteger('DurationVarId', 0);
        $this->RegisterPropertyFloat('HoldThreshold', 2.0);
        $this->RegisterPropertyInteger('RoomInstanceId', 0);

        // Ziele (Lichter / Gruppen)
        $this->RegisterPropertyString('Targets', '[]');

        // Dimmen
        $this->RegisterPropertyInteger('DimStepShort', 20);
        $this->RegisterPropertyInteger('DimStepHold', 3);

        // Farbtemperatur (COLOR_TEMP)
        $this->RegisterPropertyInteger('CTCold', 250);
        $this->RegisterPropertyInteger('CTNeutral', 370);
        $this->RegisterPropertyInteger('CTWarm', 454);

        // Button-Mapping JSON (button, gesture, actionType, param)
        $this->RegisterPropertyString('ButtonMap', '[]');

        // Attribute
        $this->RegisterAttributeInteger('LastActionVarId', 0);
        $this->RegisterAttributeInteger('DimDirection', 0);
        $this->RegisterAttributeInteger('CTSceneIndex', 0);

        // Timer für stufenloses Dimmen
        $this->RegisterTimer('DimLoop', 0, 'HZ2MR_DimLoop($_IPS["TARGET"]);');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $actionVarId     = $this->ReadPropertyInteger('ActionVarId');
        $lastActionVarId = $this->ReadAttributeInteger('LastActionVarId');

        // Alte Message deregistrieren, falls VarID geändert wurde
        if ($lastActionVarId > 0 && $lastActionVarId !== $actionVarId) {
            $this->UnregisterMessage($lastActionVarId, VM_UPDATE);
        }

        // Neue Action-Variable überwachen
        if ($actionVarId > 0 && IPS_VariableExists($actionVarId)) {
            $this->RegisterMessage($actionVarId, VM_UPDATE);
        }

        $this->WriteAttributeInteger('LastActionVarId', $actionVarId);

        // Dim-Loop immer stoppen bei Konfig-Änderungen
        $this->SetTimerInterval('DimLoop', 0);
        $this->WriteAttributeInteger('DimDirection', 0);
    }

    public function GetConfigurationForm()
    {
        // Falls du ein eigenes form.json hast, kannst du hier dynamisch Targets einsetzen.
        // Wenn du das nicht brauchst, kannst du diese Methode auch leer lassen
        // und einfach das statische form.json verwenden.
        if (!file_exists(__DIR__ . '/form.json')) {
            return json_encode(['elements' => [], 'actions' => [], 'status' => []]);
        }

        $form = json_decode(file_get_contents(__DIR__ . '/form.json'), true);

        $targetsJson = $this->ReadPropertyString('Targets');
        $targets     = json_decode($targetsJson, true);
        if (!is_array($targets)) {
            $targets = [];
        }

        if (isset($form['elements']) && is_array($form['elements'])) {
            foreach ($form['elements'] as &$element) {
                if (isset($element['type'], $element['name']) &&
                    $element['type'] === 'List' &&
                    $element['name'] === 'Targets') {
                    $element['values'] = $targets;
                }
            }
            unset($element);
        }

        return json_encode($form);
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        if ($Message === VM_UPDATE) {
            $actionVarId = $this->ReadPropertyInteger('ActionVarId');
            if ($SenderID === $actionVarId) {
                $this->HandleActionUpdate();
            }
        }
    }

    public function RequestAction($Ident, $Value)
    {
        switch ($Ident) {
            case 'LoadDefaultProfile':
                $this->LoadDefaultProfile();
                break;

            default:
                throw new Exception('Invalid Ident: ' . $Ident);
        }
    }

    /* ====================================================================== */
    /*    Z2M-Action-Handling                                                 */
    /* ====================================================================== */

    private function HandleActionUpdate(): void
    {
        $this->SendDebug('HandleActionUpdate', 'Triggered via ActionVar update', 0);
        $actionVarId = $this->ReadPropertyInteger('ActionVarId');
        if ($actionVarId <= 0 || !IPS_VariableExists($actionVarId)) {
            return;
        }

        $action = (string) GetValueString($actionVarId);
        if ($action === '') {
            return;
        }

        $this->SendDebug('Action', $action, 0);

        [$button, $gesture] = $this->ParseActionString($action);
        if ($button === '' || $gesture === '') {
            IPS_LogMessage('HueZ2MRemote', 'Ignored (no button/gesture match)');
            return;
        }

        $duration = 0.0;
        // Nur bei "long" prüfen wir die Aktionsdauer
        if ($gesture === 'long') {
            $duration  = $this->GetActionDuration();
            $threshold = $this->ReadPropertyFloat('HoldThreshold');
            IPS_LogMessage('HueZ2MRemote', sprintf('Duration=%.3fs (threshold=%.3fs)', $duration, $threshold));
            if ($duration < $threshold) {
                IPS_LogMessage('HueZ2MRemote', 'Long-press too short -> ignored');
                return;
            }
        }

        $this->ExecuteMapping($button, $gesture, $action, $duration);
    }

    /**
     * Mapping deiner Action-Strings:
     *
     * on_press
     * on_press_release
     * on_press_hold
     * on_press_hold_release
     * up_press
     * up_press_release
     * up_press_hold
     * up_press_hold_release
     * down_press
     * down_press_release
     * down_press_hold
     * down_press_hold_release
     * off_press
     * off_press_release
     * off_press_hold
     * off_press_hold_release
     *
     * -> button: on/off/up/down
     * -> gesture:
     *    - short      : *_press_release oder *_press
     *    - hold_start : *_press_hold
     *    - hold_stop  : up/down *_press_hold_release
     *    - long       : on/off *_press_hold_release  (für ON-Langdruck Automatik AUS)
     */
    private function ParseActionString(string $action): array
    {
        $this->SendDebug('ParseActionString', 'Raw=' . $action, 0);
        $lower = mb_strtolower($action);

        // Button bestimmen
        $button = '';
        if (mb_strpos($lower, 'on_') === 0) {
            $button = 'on';
        } elseif (mb_strpos($lower, 'off_') === 0) {
            $button = 'off';
        } elseif (mb_strpos($lower, 'up_') === 0) {
            $button = 'up';
        } elseif (mb_strpos($lower, 'down_') === 0) {
            $button = 'down';
        } else {
            // Fallback: generische Suche (robust gegenüber anderen Devices)
            if (mb_strpos($lower, 'off') !== false) {
                $button = 'off';
            } elseif (mb_strpos($lower, 'on') !== false) {
                $button = 'on';
            } elseif (mb_strpos($lower, 'up') !== false) {
                $button = 'up';
            } elseif (mb_strpos($lower, 'down') !== false) {
                $button = 'down';
            }
        }

        // Geste bestimmen
        $gesture = '';

        // 1) Halten losgelassen (press-basierte Variante)
        if (mb_strpos($lower, '_press_hold_release') !== false) {
            if ($button === 'on' || $button === 'off') {
                // ON/OFF lang -> Automatik
                $gesture = 'long';
            } elseif ($button === 'up' || $button === 'down') {
                // Up/Down: halten loslassen -> stufenloses Dimmen beenden
                $gesture = 'hold_stop';
            }

        // 1b) Halten losgelassen (generische *_hold_release-Variante, z. B. up_hold_release)
        } elseif (mb_strpos($lower, '_hold_release') !== false) {
            if ($button === 'up' || $button === 'down') {
                $gesture = 'hold_stop';
            } elseif ($button === 'on' || $button === 'off') {
                $gesture = 'long';
            }

        // 2) Halten begonnen (press-basierte Variante)
        } elseif (mb_strpos($lower, '_press_hold') !== false) {
            $gesture = 'hold_start';

        // 2b) Halten begonnen (generische *_hold-Variante, z. B. up_hold)
        } elseif (mb_strpos($lower, '_hold') !== false) {
            // *_hold_release wäre bereits oben abgefangen
            if ($button === 'up' || $button === 'down') {
                $gesture = 'hold_start';
            }

        // 3) normaler kurzer Klick -> nur *_press_release!
        } elseif (mb_strpos($lower, '_press_release') !== false) {
            $gesture = 'short';

        // 4) reines *_press ignorieren (kein Gesture-Flag)
        }

        $this->SendDebug('ParseResult', 'Button=' . $button . ' Gesture=' . $gesture, 0);
        return [$button, $gesture];
    }

    private function GetActionDuration(): float
    {
        $durationVarId = $this->ReadPropertyInteger('DurationVarId');
        if ($durationVarId > 0 && IPS_VariableExists($durationVarId)) {
            return (float) GetValue($durationVarId);
        }

        // Fallback: im Parent nach "Aktionsdauer" suchen (wie in deinem Skript)
        $actionVarId = $this->ReadPropertyInteger('ActionVarId');
        if ($actionVarId <= 0 || !IPS_VariableExists($actionVarId)) {
            return 0.0;
        }

        $parent = IPS_GetParent($actionVarId);
        foreach (IPS_GetChildrenIDs($parent) as $cid) {
            $o = IPS_GetObject($cid);
            if ($o['ObjectType'] === 2 /* OT_VARIABLE */ && $o['ObjectName'] === 'Aktionsdauer') {
                if (IPS_VariableExists($cid)) {
                    return (float) GetValue($cid);
                }
            }
        }

        return 0.0;
    }

    /* ====================================================================== */
    /*    ButtonMap / ActionType                                              */
    /* ====================================================================== */

    private function ExecuteMapping(string $button, string $gesture, string $action, float $duration): void
    {
        $this->SendDebug('ExecuteMapping', 'button=' . $button . ' gesture=' . $gesture, 0);
        $buttonMapJson = $this->ReadPropertyString('ButtonMap');
        $buttonMap     = json_decode($buttonMapJson, true);

        if (!is_array($buttonMap) || count($buttonMap) === 0) {
            // Fallback, wenn kein Mapping gesetzt:
            // OFF short -> alles aus + Automatik an
            // ON long   -> Automatik aus + Feedback
            if ($button === 'off' && $gesture === 'short') {
                $this->DoRmlAllOff();
                return;
            }
            if ($button === 'on' && $gesture === 'long') {
                $this->DoRmlDisableAutomation($duration);
                return;
            }
            return;
        }

        foreach ($buttonMap as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $eButton  = $entry['button'] ?? '';
            $eGesture = $entry['gesture'] ?? '';
            if ($eButton === $button && $eGesture === $gesture) {
                $this->SendDebug('MappingHit', json_encode($entry), 0);
                $actionType = $entry['actionType'] ?? '';
                $param      = $entry['param'] ?? '';
                $this->DoActionType($actionType, $param, $button, $gesture, $duration);
                return;
            }
        }

        IPS_LogMessage('HueZ2MRemote', sprintf('No mapping for button=%s gesture=%s', $button, $gesture));
    }

    private function DoActionType(string $actionType, $param, string $button, string $gesture, float $duration): void
    {
        switch ($actionType) {
            case 'RML_ALL_OFF':
                $this->DoRmlAllOff();
                break;

            case 'RML_DISABLE_AUTOMATION':
                $this->DoRmlDisableAutomation($duration);
                break;

            case 'TARGETS_ON_OR_CT':
                $this->DoTargetsOnOrCt();
                break;

            case 'TARGETS_OFF':
                $this->DoTargetsOff();
                break;

            case 'DIM_STEP':
                $step = (int) $param;
                if ($step !== 0) {
                    $this->DoDimStep($step);
                }
                break;

            case 'DIM_HOLD_START':
                $direction = 0;
                if (is_string($param)) {
                    $direction = ($param === 'up') ? 1 : (($param === 'down') ? -1 : 0);
                } else {
                    $direction = (int) $param;
                }
                if ($direction !== 0) {
                    $this->StartDimHold($direction);
                }
                break;

            case 'DIM_HOLD_STOP':
                $this->StopDimHold();
                break;

            default:
                IPS_LogMessage('HueZ2MRemote', 'Unknown actionType=' . $actionType);
                break;
        }
    }

    /* ====================================================================== */
    /*    RoomMotionLightsDev2-Integration (optional)                         */
    /* ====================================================================== */

    private function DoRmlAllOff(): void
    {
        $roomId = $this->ReadPropertyInteger('RoomInstanceId');

        // Automatik EIN schalten, falls RoomInstance gesetzt
        if ($roomId > 0 && IPS_InstanceExists($roomId)) {
            $enabledVar = @IPS_GetObjectIDByIdent('Enabled', $roomId);
            if ($enabledVar) {
                @RequestAction($enabledVar, true);
            }
        }

        // Alle Targets / Lights ausschalten
        foreach ($this->GetAllLightTargets() as $t) {
            $sv = (int) ($t['switchVar'] ?? 0);
            if ($sv > 0 && IPS_VariableExists($sv)) {
                @RequestAction($sv, false);
            }
        }
    }

    private function DoRmlDisableAutomation(float $duration): void
    {
        $roomId = $this->ReadPropertyInteger('RoomInstanceId');
        if ($roomId <= 0 || !IPS_InstanceExists($roomId)) {
            // Ohne RoomInstance: nur optisches Feedback auf Targets
            $this->DoVisualFeedback($this->GetAllLightTargets());
            return;
        }

        // Automatik AUS
        $enabledVar = @IPS_GetObjectIDByIdent('Enabled', $roomId);
        if ($enabledVar) {
            @RequestAction($enabledVar, false);
            IPS_LogMessage('HueZ2MRemote', 'Room automation disabled');
        }

        // Lights aus RoomMotionLightsDev2 für Feedback verwenden
        $lightsJson = @IPS_GetProperty($roomId, 'Lights');
        $lights     = @json_decode($lightsJson, true);
        if (!is_array($lights)) {
            $lights = [];
        }

        $this->DoVisualFeedback($lights);

        // Event-Log aktualisieren
        $eventLog = @IPS_GetObjectIDByIdent('EventLog', $roomId);
        if ($eventLog) {
            $ts    = date('H:i:s');
            $prev  = (string) @GetValueString($eventLog);
            $lines = array_filter(explode("\n", $prev), 'strlen');
            $lines[] = $ts . ' long-press → Automatik AUS (' . $duration . 's)';
            $lines   = array_slice($lines, -20);
            @SetValueString($eventLog, implode("\n", $lines));
        }
    }

    private function DoVisualFeedback(array $lights): void
    {
        $doDimPulse = function (int $dimmerVarId) {
            if (!IPS_VariableExists($dimmerVarId)) {
                return;
            }
            $cur = (int) @GetValueInteger($dimmerVarId);
            if ($cur <= 0) {
                return;
            }
            $down = max(1, (int) round($cur * 0.6)); // 40 % Dip

            @RequestAction($dimmerVarId, $down);
            IPS_Sleep(150);
            @RequestAction($dimmerVarId, $cur);
            IPS_Sleep(150);
            @RequestAction($dimmerVarId, $down);
            IPS_Sleep(150);
            @RequestAction($dimmerVarId, $cur);
        };

        $doBlink = function (int $switchVarId) {
            if (!IPS_VariableExists($switchVarId)) {
                return;
            }
            $wasOn = (bool) @GetValueBoolean($switchVarId);
            if (!$wasOn) {
                return;
            }
            @RequestAction($switchVarId, false);
            IPS_Sleep(120);
            @RequestAction($switchVarId, true);
            IPS_Sleep(120);
            @RequestAction($switchVarId, false);
            IPS_Sleep(120);
            @RequestAction($switchVarId, true);
        };

        foreach ($lights as $l) {
            $sv = (int) ($l['switchVar'] ?? 0);
            $dv = (int) ($l['dimmerVar'] ?? 0);
            if ($dv > 0 && IPS_VariableExists($dv)) {
                $doDimPulse($dv);
            } elseif ($sv > 0 && IPS_VariableExists($sv)) {
                $doBlink($sv);
            }
        }
    }

    /* ====================================================================== */
    /*    Targets / COLOR_TEMP / Dimmen                                      */
    /* ====================================================================== */

    private function GetAllLightTargets(): array
    {
        // 1. Priorität: eigene Targets-Property
        $targetsJson = $this->ReadPropertyString('Targets');
        $targets     = @json_decode($targetsJson, true);
        if (is_array($targets) && count($targets) > 0) {
            return $targets;
        }

        // 2. Fallback: Lights aus der RoomMotionLightsDev2-Instanz
        $roomId = $this->ReadPropertyInteger('RoomInstanceId');
        if ($roomId > 0 && IPS_InstanceExists($roomId)) {
            $lightsJson = @IPS_GetProperty($roomId, 'Lights');
            $lights     = @json_decode($lightsJson, true);
            if (is_array($lights)) {
                return $lights;
            }
        }

        return [];
    }

    private function DoTargetsOnOrCt(): void
    {
        $targets = $this->GetAllLightTargets();
        if (count($targets) === 0) {
            return;
        }

        if (!$this->AnyTargetOn($targets)) {
            $this->TurnTargetsOn($targets);
        } else {
            $this->CycleColorTemperature($targets);
        }
    }

    private function DoTargetsOff(): void
    {
        $targets = $this->GetAllLightTargets();
        foreach ($targets as $t) {
            $sv = (int) ($t['switchVar'] ?? 0);
            if ($sv > 0 && IPS_VariableExists($sv)) {
                @RequestAction($sv, false);
            }
        }
    }

    private function AnyTargetOn(array $targets): bool
    {
        foreach ($targets as $t) {
            $sv = (int) ($t['switchVar'] ?? 0);
            if ($sv > 0 && IPS_VariableExists($sv)) {
                if ((bool) @GetValueBoolean($sv)) {
                    return true;
                }
            }
        }
        return false;
    }

    private function TurnTargetsOn(array $targets): void
    {
        foreach ($targets as $t) {
            $sv = (int) ($t['switchVar'] ?? 0);
            if ($sv > 0 && IPS_VariableExists($sv)) {
                @RequestAction($sv, true);
            }
        }
    }
    private function DetectCtIndexFromCurrent(array $targets, array $ctValues): int
{
    foreach ($targets as $t) {
        $ctVar = (int)($t['ctVar'] ?? 0);
        if ($ctVar > 0 && IPS_VariableExists($ctVar)) {
            $cur = (int)@GetValueInteger($ctVar);
            $this->SendDebug('CT', 'CurrentCT=' . $cur, 0);

            foreach ($ctValues as $idx => $val) {
                if ($cur === $val) {
                    $this->SendDebug('CT', 'CurrentCT matches index ' . $idx, 0);
                    return $idx;
                }
            }
            // Ersten CT gefunden, aber keiner unserer definierten Werte
            $this->SendDebug('CT', 'CurrentCT not in defined list', 0);
            return -1;
        }
    }

    // Kein ctVar vorhanden
    $this->SendDebug('CT', 'No ctVar found on targets', 0);
    return -1;
}

private function CycleColorTemperature(array $targets): void
{
    $this->SendDebug('CT', 'CycleColorTemperature called', 0);

    // CTs aus Properties
    $ctValues = [
        $this->ReadPropertyInteger('CTCold'),
        $this->ReadPropertyInteger('CTNeutral'),
        $this->ReadPropertyInteger('CTWarm')
    ];

    // Nur sinnvolle Werte (>0) verwenden
    $ctValues = array_values(array_filter($ctValues, function ($v) {
        return $v > 0;
    }));
    $this->SendDebug('CT', 'Values=' . json_encode($ctValues), 0);

    if (count($ctValues) === 0) {
        $this->SendDebug('CT', 'No CT values configured', 0);
        return;
    }

    // Aktuelle CT-Stufe am ersten Ziel ermitteln
    $currentIndex = $this->DetectCtIndexFromCurrent($targets, $ctValues);

    if ($currentIndex >= 0) {
        $nextIndex = ($currentIndex + 1) % count($ctValues);
        $this->SendDebug('CT', 'Detected index ' . $currentIndex . ' -> nextIndex=' . $nextIndex, 0);
    } else {
        // Kein passender Wert → bei erster Stufe anfangen
        $nextIndex = 0;
        $this->SendDebug('CT', 'No matching CT, starting at index 0', 0);
    }

    $this->WriteAttributeInteger('CTSceneIndex', $nextIndex);

    $ct = $ctValues[$nextIndex];
    $this->SendDebug('CT', 'SelectedCT=' . $ct, 0);

    foreach ($targets as $t) {
        $ctVar = (int)($t['ctVar'] ?? 0);
        if ($ctVar > 0 && IPS_VariableExists($ctVar)) {
            @RequestAction($ctVar, $ct);
        }
    }
}

    private function DoDimStep(int $step): void
    {
        $this->SendDebug('DoDimStep', 'step=' . $step, 0);
        $targets = $this->GetAllLightTargets();
        if (count($targets) === 0) {
            return;
        }

        foreach ($targets as $t) {
            $dv = (int) ($t['dimmerVar'] ?? 0);
            if ($dv > 0 && IPS_VariableExists($dv)) {
                $cur = (int) @GetValueInteger($dv);
                $new = max(0, min(100, $cur + $step));
                @RequestAction($dv, $new);
            }
        }
    }

    private function StartDimHold(int $direction): void
    {
        $this->SendDebug('StartDimHold', 'direction=' . $direction, 0);
        $this->WriteAttributeInteger('DimDirection', $direction);
        $this->SetTimerInterval('DimLoop', 150); // alle 150ms ein Schritt
    }

    private function StopDimHold(): void
    {
        $this->SendDebug('StopDimHold', 'Stopping dim loop', 0);
        $this->WriteAttributeInteger('DimDirection', 0);
        $this->SetTimerInterval('DimLoop', 0);
    }

    public function DimLoop(): void
    {
        $this->SendDebug('DimLoop', 'direction=' . $this->ReadAttributeInteger('DimDirection'), 0);
        $direction = $this->ReadAttributeInteger('DimDirection');
        if ($direction === 0) {
            $this->SetTimerInterval('DimLoop', 0);
            return;
        }

        $step = $this->ReadPropertyInteger('DimStepHold');
        if ($step <= 0) {
            $step = 1;
        }

        $this->DoDimStep($direction * $step);
    }

    /* ====================================================================== */
    /*    Default-Profil                                                      */
    /* ====================================================================== */

    public function LoadDefaultProfile(): void
    {
        $shortStep = $this->ReadPropertyInteger('DimStepShort');

        $default = [
            [
                'button'     => 'on',
                'gesture'    => 'short',
                'actionType' => 'TARGETS_ON_OR_CT',
                'param'      => ''
            ],
            [
                'button'     => 'off',
                'gesture'    => 'short',
                'actionType' => 'RML_ALL_OFF',
                'param'      => ''
            ],
            [
                'button'     => 'up',
                'gesture'    => 'short',
                'actionType' => 'DIM_STEP',
                'param'      => $shortStep
            ],
            [
                'button'     => 'down',
                'gesture'    => 'short',
                'actionType' => 'DIM_STEP',
                'param'      => -$shortStep
            ],
            [
                'button'     => 'up',
                'gesture'    => 'hold_start',
                'actionType' => 'DIM_HOLD_START',
                'param'      => 'up'
            ],
            [
                'button'     => 'up',
                'gesture'    => 'hold_stop',
                'actionType' => 'DIM_HOLD_STOP',
                'param'      => ''
            ],
            [
                'button'     => 'down',
                'gesture'    => 'hold_start',
                'actionType' => 'DIM_HOLD_START',
                'param'      => 'down'
            ],
            [
                'button'     => 'down',
                'gesture'    => 'hold_stop',
                'actionType' => 'DIM_HOLD_STOP',
                'param'      => ''
            ],
            [
                'button'     => 'on',
                'gesture'    => 'long',
                'actionType' => 'RML_DISABLE_AUTOMATION',
                'param'      => ''
            ]
        ];

        $this->UpdateButtonMap($default);
        IPS_LogMessage('HueZ2MRemote', 'Default Hue profile loaded');
    }

    private function UpdateButtonMap(array $map): void
    {
        $json = json_encode($map);
        IPS_SetProperty($this->InstanceID, 'ButtonMap', $json);
        IPS_ApplyChanges($this->InstanceID);
    }
}