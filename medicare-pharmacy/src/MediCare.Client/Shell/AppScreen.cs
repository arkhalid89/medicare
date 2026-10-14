namespace MediCare.Client.Shell;

/// <summary>
/// Base for every screen hosted in the shell. Screens are created once and kept, so switching away
/// from POS and back keeps the cart.
/// </summary>
public abstract class AppScreen : UserControl
{
    private bool _loaded;
    protected readonly MessageBar Bar = new MessageBar();

    protected AppScreen()
    {
        BackColor = Theme.Canvas;
        Dock = DockStyle.Fill;
        Font = Theme.Ui;
        ForeColor = Theme.Ink;
        Padding = new Padding(Theme.S(20), Theme.S(12), Theme.S(20), Theme.S(12));
    }

    public abstract string Title { get; }
    public virtual string Subtitle => "";
    public MainForm Shell { get; internal set; } = null!;

    /// <summary>Keys shown on the keycap strip and handled while this screen is active.</summary>
    public virtual IEnumerable<FKey> FKeys => Array.Empty<FKey>();

    internal async Task Activate()
    {
        if (!_loaded)
        {
            _loaded = true;
            await Run(OnFirstShow);
        }
        else await Run(OnReshow);
        FocusFirst();
    }

    /// <summary>Load data the first time the screen opens.</summary>
    protected virtual Task OnFirstShow() => Task.CompletedTask;

    /// <summary>Refresh when the person comes back to the screen.</summary>
    protected virtual Task OnReshow() => OnFirstShow();

    /// <summary>Puts the cursor where work starts (search box, scan field).</summary>
    public virtual void FocusFirst() { }

    /// <summary>Host events (approvals, settings) while the screen exists.</summary>
    public virtual void OnHostEvent(EventDto e) { }

    public virtual bool HandleKey(Keys key)
    {
        var k = FKeys.FirstOrDefault(f => f.Key == key);
        if (k == null || !(k.Enabled?.Invoke() ?? true)) return false;
        k.Action();
        return true;
    }

    protected Task Run(Func<Task> work) => UiRun.Run(this, work, Bar);
    protected void Fire(Func<Task> work) => UiRun.Fire(this, work, Bar);

    /// <summary>Standard screen frame: message bar on top, then the given rows; put a Fill control last.</summary>
    protected void Compose(params Control[] rows)
    {
        var all = new List<Control> { Bar };
        all.AddRange(rows);
        this.Stack(all.ToArray());
    }
}
