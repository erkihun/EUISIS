# Field Work and Attendance

The current EUISIS repository has no attendance module. Field Work therefore creates no clock-in/out data, no fabricated biometric events, and no “present” or “absent” status.

When attendance is introduced, it should query approved field-work intervals as an `OFFICIAL_FIELD_WORK` exception, including partial-day boundaries, and retain physical attendance events as a separate domain. Overlapping leave, training, mission, and attendance precedence require documented policy before implementation.

Daily Work is also separate: the existing Daily Activity module already has a `field_work` category but this module neither creates a daily log nor creates EPMS scores.
