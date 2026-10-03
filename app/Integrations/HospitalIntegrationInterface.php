<?php
// app/Integrations/HospitalIntegrationInterface.php - Integration Contract

namespace Pharmacy\Integrations;

interface HospitalIntegrationInterface
{
    /**
     * Search hospital patient directory by UHID, mobile, or name.
     */
    public function findHospitalPatient(string $query): array;

    /**
     * Retrieve full details of a specific hospital patient.
     */
    public function getHospitalPatient(int $hospitalPatientId): array;

    /**
     * Search for an active IPD admission record.
     */
    public function findActiveIPD($patientOrAdmission): array;

    /**
     * Retrieve admission and bed details for an IPD encounter.
     */
    public function getIPDDetails(int $admissionId): array;

    /**
     * Fetch active doctor OPD prescriptions for a hospital patient.
     */
    public function getOPDPrescriptions(int $patientId): array;

    /**
     * Post a cashless/credit pharmacy charge to the patient's hospital billing account.
     */
    public function postIPDPharmacyCharge(array $chargeData): array;

    /**
     * Check if an IPD patient has been cleared for discharge or billed.
     */
    public function getIPDBillingStatus(int $admissionId): array;

    /**
     * Link an existing local Pharmacy Patient ID to a Hospital Patient.
     */
    public function linkPharmacyPatient(int $pharmacyPatientId, int $hospitalPatientId): array;

    /**
     * Mark an electronic prescription as partially or fully dispensed.
     */
    public function markPrescriptionDispensed(int $prescriptionId, array $dispenseDetails): array;
}
