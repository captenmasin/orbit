$ErrorActionPreference = 'Stop'
Add-Type -TypeDefinition @'
using System;
using System.Diagnostics;
using System.Runtime.InteropServices;
using System.Threading;

public static class OrbitWindowsHello
{
    [DllImport("combase.dll")] private static extern int RoInitialize(uint type);
    [DllImport("combase.dll")] private static extern void RoUninitialize();
    [DllImport("combase.dll", CharSet = CharSet.Unicode)] private static extern int WindowsCreateString(string value, uint length, out IntPtr handle);
    [DllImport("combase.dll")] private static extern int WindowsDeleteString(IntPtr handle);
    [DllImport("combase.dll")] private static extern int RoGetActivationFactory(IntPtr name, ref Guid iid, out IntPtr factory);
    [DllImport("user32.dll")] [return: MarshalAs(UnmanagedType.Bool)] private static extern bool IsWindow(IntPtr window);

    [UnmanagedFunctionPointer(CallingConvention.StdCall)] private delegate int StartOperation(IntPtr self, out IntPtr operation);
    [UnmanagedFunctionPointer(CallingConvention.StdCall)] private delegate int VerifyForWindow(IntPtr self, IntPtr window, IntPtr message, ref Guid iid, out IntPtr operation);
    [UnmanagedFunctionPointer(CallingConvention.StdCall)] private delegate int ReadEnum(IntPtr self, out int value);
    [UnmanagedFunctionPointer(CallingConvention.StdCall)] private delegate int CancelOperation(IntPtr self);

    private static T Method<T>(IntPtr instance, int slot) where T : class
    {
        return (T)(object)Marshal.GetDelegateForFunctionPointer(Marshal.ReadIntPtr(Marshal.ReadIntPtr(instance), slot * IntPtr.Size), typeof(T));
    }

    public static bool Run(bool verify, long windowHandle)
    {
        IntPtr name = IntPtr.Zero, factory = IntPtr.Zero, message = IntPtr.Zero;
        IntPtr operation = IntPtr.Zero, info = IntPtr.Zero;
        bool initialized = false;
        try
        {
            Marshal.ThrowExceptionForHR(RoInitialize(1));
            initialized = true;
            string className = "Windows.Security.Credentials.UI.UserConsentVerifier";
            Marshal.ThrowExceptionForHR(WindowsCreateString(className, (uint)className.Length, out name));
            Guid factoryId = new Guid("39e050c3-4e74-441a-8dc0-b81104df949c");
            Marshal.ThrowExceptionForHR(RoGetActivationFactory(name, ref factoryId, out factory));
            if (!verify)
            {
                Marshal.Release(factory);
                factory = IntPtr.Zero;
                factoryId = new Guid("af4f3f91-564c-4ddc-b8b5-973447627c65");
                Marshal.ThrowExceptionForHR(RoGetActivationFactory(name, ref factoryId, out factory));
            }
            if (verify)
            {
                IntPtr window = new IntPtr(windowHandle);
                if (!IsWindow(window)) return false;
                string reason = "Reset your Orbit secrets PIN";
                Marshal.ThrowExceptionForHR(WindowsCreateString(reason, (uint)reason.Length, out message));
                // IAsyncOperation<UserConsentVerificationResult>, as defined by the Windows Runtime ABI.
                Guid resultId = new Guid("fd596ffd-2318-558f-9dbe-d21df43764a5");
                Marshal.ThrowExceptionForHR(Method<VerifyForWindow>(factory, 6)(factory, window, message, ref resultId, out operation));
            }
            else
            {
                Marshal.ThrowExceptionForHR(Method<StartOperation>(factory, 6)(factory, out operation));
            }

            Guid asyncInfoId = new Guid("00000036-0000-0000-c000-000000000046");
            Marshal.ThrowExceptionForHR(Marshal.QueryInterface(operation, ref asyncInfoId, out info));
            Stopwatch deadline = Stopwatch.StartNew();
            int status;
            do
            {
                // IInspectable occupies slots 0-5; IAsyncInfo.Status is slot 7.
                Marshal.ThrowExceptionForHR(Method<ReadEnum>(info, 7)(info, out status));
                if (status != 0) break;
                if (deadline.ElapsedMilliseconds >= (verify ? 90000 : 5000)) return false;
                Thread.Sleep(100);
            } while (true);
            if (status != 1) return false;

            int result;
            Marshal.ThrowExceptionForHR(Method<ReadEnum>(operation, 8)(operation, out result));
            return result == 0; // Only Available / Verified succeed; cancellation and every other result fail closed.
        }
        catch
        {
            return false;
        }
        finally
        {
            if (info != IntPtr.Zero)
            {
                int status;
                if (Method<ReadEnum>(info, 7)(info, out status) >= 0 && status == 0)
                    Method<CancelOperation>(info, 9)(info);
                Marshal.Release(info);
            }
            if (operation != IntPtr.Zero) Marshal.Release(operation);
            if (factory != IntPtr.Zero) Marshal.Release(factory);
            if (message != IntPtr.Zero) WindowsDeleteString(message);
            if (name != IntPtr.Zero) WindowsDeleteString(name);
            if (initialized) RoUninitialize();
        }
    }
}
'@
