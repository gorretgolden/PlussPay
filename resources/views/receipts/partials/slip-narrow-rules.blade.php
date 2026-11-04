/* The narrow set, written once and switched on twice: by a small window, and by a small sheet of
   paper on the print page. A receipt printed on a roll is narrow because of the roll, not because
   whoever opened it has a narrow window, so the two guards have to exist separately. */
.slip { padding: 16px 14px; border-left-width: 4px; }
.slip-company { height: 48px; }
.slip-route { margin: 11px 0 9px; }
.slip-parties { grid-template-columns: 1fr; }
.slip-party + .slip-party {
    margin-top: 7px;
    padding-top: 7px;
    padding-left: 0;
    border-top: 1px solid var(--slip-line);
    border-left: 0;
}
.slip-table th,
.slip-table td { padding: 6px 5px; }
.slip-table thead th { font-size: 8px; letter-spacing: .04em; }
.slip-table tbody td { font-size: 10px; }
.slip-particulars strong { font-size: 10.5px; }
.slip-rate { white-space: normal; }
.slip-figure,
.slip-payout,
.slip-total { overflow-wrap: anywhere; }
.slip-signatures { grid-template-columns: 1fr; }
.slip-sign + .slip-sign { border-top: 1px solid var(--slip-rule); border-left: 0; }
.slip-foot { grid-template-columns: 1fr; gap: 8px; }
