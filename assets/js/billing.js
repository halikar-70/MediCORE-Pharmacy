// assets/js/billing.js
function recalcBillTotal() {
  let total = 0;
  document.querySelectorAll(".bill-item-amount").forEach(function (el) {
    total += parseFloat(el.value || 0);
  });
  const totalField = document.getElementById("total_amount");
  if (totalField) totalField.value = total.toFixed(2);

  const paid = parseFloat(document.getElementById("paid_amount")?.value || 0);
  const balanceField = document.getElementById("balance_amount");
  if (balanceField) balanceField.value = (total - paid).toFixed(2);
}

document.addEventListener("input", function (e) {
  if (
    e.target.classList.contains("bill-item-amount") ||
    e.target.id === "paid_amount"
  ) {
    recalcBillTotal();
  }
});
