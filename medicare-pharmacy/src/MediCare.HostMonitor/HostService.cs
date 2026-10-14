using System.ComponentModel;
using System.ServiceProcess;

namespace MediCare.HostMonitor;

public enum ServiceState { NotInstalled, Stopped, Starting, Stopping, Running, Paused, Unknown }

public sealed record ServiceActionResult(bool Ok, bool NeedsAdmin, string Message);

/// <summary>Queries and controls the MediCare host Windows service.</summary>
public static class HostService
{
    public const string Name = "MediCarePharmacyHost";

    private static readonly TimeSpan StopTimeout = TimeSpan.FromSeconds(60);
    // First start after an upgrade runs database migrations, so allow more time than for a stop.
    private static readonly TimeSpan StartTimeout = TimeSpan.FromSeconds(120);

    private const int ErrorAccessDenied = 5;
    private const int ErrorServiceRequestTimeout = 1053;
    private const int ErrorServiceAlreadyRunning = 1056;
    private const int ErrorServiceDoesNotExist = 1060;
    private const int ErrorServiceNotActive = 1062;
    private const int ErrorServiceDisabled = 1058;

    public static ServiceState Query()
    {
        try
        {
            using var sc = new ServiceController(Name);
            return sc.Status switch
            {
                ServiceControllerStatus.Running => ServiceState.Running,
                ServiceControllerStatus.Stopped => ServiceState.Stopped,
                ServiceControllerStatus.StartPending or ServiceControllerStatus.ContinuePending => ServiceState.Starting,
                ServiceControllerStatus.StopPending or ServiceControllerStatus.PausePending => ServiceState.Stopping,
                ServiceControllerStatus.Paused => ServiceState.Paused,
                _ => ServiceState.Unknown,
            };
        }
        catch (InvalidOperationException ex) when (Win32Code(ex) == ErrorServiceDoesNotExist)
        {
            return ServiceState.NotInstalled;
        }
        catch (Exception ex) when (ex is InvalidOperationException or Win32Exception)
        {
            return ServiceState.Unknown;
        }
    }

    public static Task<ServiceActionResult> StartAsync() => Task.Run(() => Run(sc =>
    {
        StartAndWait(sc);
        return "The host service is running.";
    }));

    public static Task<ServiceActionResult> StopAsync() => Task.Run(() => Run(sc =>
    {
        StopAndWait(sc);
        return "The host service is stopped. Counter PCs cannot post sales until it is started again.";
    }));

    public static Task<ServiceActionResult> RestartAsync() => Task.Run(() => Run(sc =>
    {
        StopAndWait(sc);
        StartAndWait(sc);
        return "The host service was restarted.";
    }));

    private static void StartAndWait(ServiceController sc)
    {
        sc.Refresh();
        if (sc.Status == ServiceControllerStatus.Running) return;
        if (sc.Status != ServiceControllerStatus.StartPending)
        {
            try
            {
                sc.Start();
            }
            catch (InvalidOperationException ex) when (Win32Code(ex) == ErrorServiceAlreadyRunning)
            {
            }
        }
        sc.WaitForStatus(ServiceControllerStatus.Running, StartTimeout);
    }

    private static void StopAndWait(ServiceController sc)
    {
        sc.Refresh();
        if (sc.Status == ServiceControllerStatus.Stopped) return;
        if (sc.Status != ServiceControllerStatus.StopPending)
        {
            try
            {
                sc.Stop();
            }
            catch (InvalidOperationException ex) when (Win32Code(ex) == ErrorServiceNotActive)
            {
            }
        }
        sc.WaitForStatus(ServiceControllerStatus.Stopped, StopTimeout);
    }

    private static ServiceActionResult Run(Func<ServiceController, string> action)
    {
        try
        {
            using var sc = new ServiceController(Name);
            return new ServiceActionResult(true, false, action(sc));
        }
        catch (System.ServiceProcess.TimeoutException)
        {
            return new ServiceActionResult(false, false,
                "The service did not finish in time. It may still be busy (for example migrating the database after an upgrade). " +
                "Wait a minute; if the state does not change, open the logs folder and check today's host log.");
        }
        catch (InvalidOperationException ex)
        {
            return Win32Code(ex) switch
            {
                ErrorAccessDenied => new ServiceActionResult(false, true,
                    "Windows did not allow this. Starting and stopping the host service needs administrator rights."),
                ErrorServiceDoesNotExist => new ServiceActionResult(false, false,
                    $"The service \"{Name}\" is not installed on this PC. Run the MediCare Host installer again."),
                ErrorServiceDisabled => new ServiceActionResult(false, false,
                    "The service is disabled in Windows Services. Set its startup type to Automatic (Delayed Start) and try again."),
                ErrorServiceRequestTimeout => new ServiceActionResult(false, false,
                    "The service did not respond. Open the logs folder and check today's host log for the reason."),
                _ => new ServiceActionResult(false, false, "The service could not be controlled: " + (ex.InnerException?.Message ?? ex.Message)),
            };
        }
    }

    private static int? Win32Code(Exception ex) => (ex.InnerException as Win32Exception)?.NativeErrorCode;
}
