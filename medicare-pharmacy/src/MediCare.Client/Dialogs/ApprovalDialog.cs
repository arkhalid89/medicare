namespace MediCare.Client.Dialogs;

/// <summary>
/// SCR-07 Manager approval. Either the manager enters their PIN on this PC (inline), or the request is sent
/// to the Approvals screen and this dialog waits for the decision. Returns a single-use approval token.
/// </summary>
public sealed class ApprovalDialog : DialogForm
{
    private readonly string _action, _contextKey;
    private readonly decimal _value;
    private readonly UiCombo _approver = new UiCombo();
    private readonly UiText _pin = new UiText { UseSystemPasswordChar = true, MaxLength = 6 };
    private readonly UiText _reason = new UiText("e.g. senior citizen, damaged strip");
    private readonly Timer _poll = new Timer { Interval = 2000 };
    private long? _requestId;

    public string? Token { get; private set; }
    public string ApproverName { get; private set; } = "";

    public ApprovalDialog(string action, string contextKey, decimal value, string message, string summary) : base("Manager approval", 520)
    {
        _action = action;
        _contextKey = contextKey;
        _value = value;
        var intro = new UiLabel(message, Theme.UiBold).Wrap(Theme.S(470));
        var detail = new UiLabel(summary.Length > 0 ? summary : ApprovalActions.Label(action) + ": " + FormatValue(action, value), Theme.Ui, Theme.InkMuted).Wrap(Theme.S(470));
        var f = new FieldGrid(1);
        f.Add("Approver", _approver);
        f.Add("Approver PIN", _pin, hint: "The approver types their own PIN. It is never shown or stored on this PC.");
        f.Add("Reason", _reason);
        Body.Stack(intro, Box.Gap(4), detail, Box.Gap(12), f);
        AddOk("Approve", ApproveInline);
        AddCancel();
        AddButton("Send to manager's screen", () => UiRun.Fire(this, SendDeferred), ButtonKind.Ghost);
        _poll.Tick += async (_, _) => await CheckDeferred();
        FitToContent();
        Load += async (_, _) => await UiRun.Run(this, LoadApprovers, Bar);
    }

    private static string FormatValue(string action, decimal v) =>
        action == ApprovalActions.Discount ? v.ToString("0.##", CultureInfo.InvariantCulture) + "%" : Money.FormatRs(v);

    private async Task LoadApprovers()
    {
        var list = await Session.Api.Get<List<IdName>>("approvers", new { action = _action });
        _approver.SetItems(list.Where(a => a.Id != Session.User.Id));
        if (_approver.Items.Count == 0)
            Fail("Nobody with a PIN can approve this. Ask the administrator to give a manager this permission and a PIN, or use \"Send to manager's screen\".", Tone.Warn);
    }

    private async Task<bool> ApproveInline()
    {
        var who = _approver.Selected<IdName>();
        if (who == null) { Fail("Choose who is approving."); return false; }
        if (_pin.Text.Length < 4) { Fail("The approver must enter their 4 to 6 digit PIN."); _pin.Focus(); return false; }
        if (_reason.Text.Trim().Length == 0) { Fail("Enter the reason for this approval. It is kept in the audit log."); _reason.Focus(); return false; }
        var username = who.Name.Contains("(") ? who.Name.Substring(who.Name.LastIndexOf('(') + 1).TrimEnd(')') : who.Name;
        try
        {
            var r = await Session.Api.Post<ApprovalTokenResponse>("approvals/inline", new InlineApprovalRequest
            {
                Action = _action, ContextKey = _contextKey, Value = _value, ApproverUsername = username, Pin = _pin.Text, Reason = _reason.Text.Trim(),
            });
            Token = r.Token;
            ApproverName = r.ApproverName;
            return true;
        }
        finally { _pin.Clear(); }
    }

    private async Task SendDeferred()
    {
        if (_reason.Text.Trim().Length == 0) { Fail("Enter the reason first, so the manager knows what they are approving."); _reason.Focus(); return; }
        var r = await Session.Api.Post<ApprovalDto>("approvals", new DeferredApprovalRequest
        {
            Action = _action, ContextKey = _contextKey, Value = _value, Reason = _reason.Text.Trim(),
            Summary = $"{ApprovalActions.Label(_action)}: {FormatValue(_action, _value)} at counter {Session.Counter} ({Session.User.FullName})",
        });
        _requestId = r.Id;
        Fail("Request sent. Waiting for a manager to approve it on the Approvals screen…", Tone.Info);
        _approver.Enabled = _pin.Enabled = _reason.Enabled = false;
        _poll.Start();
    }

    private async Task CheckDeferred()
    {
        if (_requestId == null) return;
        try
        {
            var r = await Session.Api.Get<ApprovalDto>($"approvals/{_requestId}");
            if (r.Status == "APPROVED" && !string.IsNullOrEmpty(r.Token))
            {
                _poll.Stop();
                Token = r.Token;
                ApproverName = r.DecidedBy ?? "";
                DialogResult = DialogResult.OK;
                Close();
            }
            else if (r.Status == "REJECTED")
            {
                _poll.Stop();
                _approver.Enabled = _pin.Enabled = _reason.Enabled = true;
                _requestId = null;
                Fail($"{r.DecidedBy} rejected the request: {r.Reason}", Tone.Danger);
            }
        }
        catch (ApiException) { /* keep waiting; the status bar shows connection problems */ }
    }

    protected override void OnFormClosed(FormClosedEventArgs e)
    {
        _poll.Stop();
        _poll.Dispose();
        base.OnFormClosed(e);
    }
}

/// <summary>
/// Runs a posting; when the host answers APPROVAL_REQUIRED, asks for the approval and retries with the token.
/// Repeats if a second approval is needed (e.g. discount and credit limit on one sale).
/// </summary>
public static class Approvals
{
    public static async Task<T> Run<T>(IWin32Window owner, Func<string[], Task<T>> call, string summary = "")
    {
        var tokens = new List<string>();
        for (int round = 0; ; round++)
        {
            try { return await call(tokens.ToArray()); }
            catch (ApiException ex) when (ex.Code == ErrorCodes.ApprovalRequired && round < 4)
            {
                var action = ex.Details.Get("action") ?? "";
                var key = ex.Details.Get("contextKey") ?? "";
                var value = decimal.TryParse(ex.Details.Get("value"), NumberStyles.Any, CultureInfo.InvariantCulture, out var v) ? v : 0m;
                using var d = new ApprovalDialog(action, key, value, ex.Message, summary);
                if (d.ShowDialog(owner) != DialogResult.OK || d.Token == null) throw new OperationCanceledException();
                tokens.Add(d.Token);
            }
        }
    }
}
