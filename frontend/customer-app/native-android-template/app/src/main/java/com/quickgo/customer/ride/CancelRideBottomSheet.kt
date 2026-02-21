package com.quickgo.customer.ride

import android.app.Dialog
import android.os.Bundle
import android.view.LayoutInflater
import android.view.View
import android.widget.RadioButton
import android.widget.RadioGroup
import com.google.android.material.bottomsheet.BottomSheetDialog
import com.google.android.material.bottomsheet.BottomSheetDialogFragment
import com.google.android.material.button.MaterialButton
import com.quickgo.customer.R

class CancelRideBottomSheet(
    private val onReasonSelected: (String) -> Unit
) : BottomSheetDialogFragment() {

    override fun onCreateDialog(savedInstanceState: Bundle?): Dialog {
        val dialog = BottomSheetDialog(requireContext())
        val view = LayoutInflater.from(requireContext())
            .inflate(R.layout.bottom_sheet_cancel_ride, null)

        val radioGroup = view.findViewById<RadioGroup>(R.id.radioReasons)
        val submit = view.findViewById<MaterialButton>(R.id.btnSubmitCancel)

        submit.setOnClickListener {
            val reason = selectedReason(radioGroup)
            if (reason.isNotEmpty()) {
                onReasonSelected(reason)
                dismiss()
            }
        }

        dialog.setContentView(view)
        return dialog
    }

    private fun selectedReason(group: RadioGroup): String {
        val id = group.checkedRadioButtonId
        if (id == View.NO_ID) return ""
        val selected = group.findViewById<RadioButton>(id)
        return selected.text.toString().trim()
    }
}
