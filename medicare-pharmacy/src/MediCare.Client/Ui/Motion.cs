using System.Diagnostics;

namespace MediCare.Client.Ui;

/// <summary>
/// Tiny tween engine on one UI-thread timer: hover fades, count-ups, chart reveals, toasts.
/// Every animation is short and purely visual. With animations off (This PC settings, or a
/// Remote Desktop session) each tween jumps straight to its end state.
/// </summary>
public static class Motion
{
    private sealed class Tween
    {
        public Control Owner = null!;
        public string Key = "";
        public long Start;
        public int Ms;
        public Action<float> Step = null!;
        public Action? Done;
        public Func<float, float> Ease = null!;
    }

    private static readonly List<Tween> Active = new List<Tween>();
    private static readonly Stopwatch Clock = Stopwatch.StartNew();
    private static Timer? _timer;

    public static bool Enabled { get; set; } = true;

    /// <summary>Runs <paramref name="step"/> with progress 0..1 over <paramref name="ms"/>. A new tween with the same owner and key replaces the old one.</summary>
    public static void Run(Control owner, string key, int ms, Action<float> step, Action? done = null, Func<float, float>? ease = null)
    {
        Active.RemoveAll(t => t.Owner == owner && t.Key == key);
        if (!Enabled || ms <= 0 || owner.IsDisposed)
        {
            step(1f);
            done?.Invoke();
            return;
        }
        var tween = new Tween { Owner = owner, Key = key, Start = Clock.ElapsedMilliseconds, Ms = ms, Step = step, Done = done, Ease = ease ?? EaseOut };
        Active.Add(tween);
        step(tween.Ease(0f));
        if (_timer == null)
        {
            _timer = new Timer { Interval = 15 };
            _timer.Tick += (_, _) => Tick();
        }
        _timer.Start();
    }

    public static void Stop(Control owner, string key) => Active.RemoveAll(t => t.Owner == owner && t.Key == key);

    public static bool IsRunning(Control owner, string key) => Active.Any(t => t.Owner == owner && t.Key == key);

    private static void Tick()
    {
        var now = Clock.ElapsedMilliseconds;
        foreach (var t in Active.ToArray())
        {
            if (!Active.Contains(t)) continue;
            if (t.Owner.IsDisposed) { Active.Remove(t); continue; }
            var p = Math.Min(1f, (now - t.Start) / (float)t.Ms);
            try { t.Step(t.Ease(p)); }
            catch (Exception ex) { Log.Error("Animation step failed", ex); Active.Remove(t); continue; }
            if (p >= 1f)
            {
                Active.Remove(t);
                t.Done?.Invoke();
            }
        }
        if (Active.Count == 0) _timer?.Stop();
    }

    public static float Linear(float t) => t;
    public static float EaseOut(float t) => 1f - (1f - t) * (1f - t) * (1f - t);
    public static float EaseInOut(float t) => t < 0.5f ? 4f * t * t * t : 1f - (float)Math.Pow(-2f * t + 2f, 3) / 2f;

    /// <summary>Overshoots slightly, then settles (pop-in).</summary>
    public static float Back(float t)
    {
        const float c1 = 1.70158f, c3 = c1 + 1f;
        return 1f + c3 * (float)Math.Pow(t - 1f, 3) + c1 * (float)Math.Pow(t - 1f, 2);
    }

    public static float Lerp(float a, float b, float t) => a + (b - a) * t;
}
