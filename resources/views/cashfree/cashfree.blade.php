<!DOCTYPE html>
<html lang="en">
  <head>
    <script src="https://sdk.cashfree.com/js/v3/cashfree.js"></script>
  </head>
  <body>
    <button type="button" id="renderBtn">Pay Now</button>
  </body>
  <script>
      const cashfree = Cashfree({
        mode: "sandbox" // or "production" based on your environment
      });

      document.getElementById("renderBtn").addEventListener("click", () => {
        cashfree.checkout({
          paymentSessionId: "{{ $paymentSessionId }}",
          amount: "{{ $amount }}",
          currency: "{{ $currency }}",
          phone_mobile: "{{ $phone_mobile }}",
          successCallback: function(response) {
              console.log('Payment Successful:', response);
              // Handle successful payment, e.g., redirect to success page or notify backend
          },
          errorCallback: function(response) {
              console.log('Payment Error:', response);
              // Handle payment error
          }
        });
      });
  </script>
</html>
