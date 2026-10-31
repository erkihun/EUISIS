<?php


test('applicant registration rejects weak passwords', function () {
    $response = $this->post(route('court.applicant.register.submit'), [
        'first_name' => 'Test',
        'middle_name' => 'Weak',
        'last_name' => 'Applicant',
        'gender' => 'male',
        'position' => 'Officer',
        'organization_name' => 'Test Org',
        'phone' => '0911000000',
        'email' => 'weak-applicant@example.com',
        'address' => 'Addis Ababa',
        'is_lawyer' => 0,
        'national_id_number' => '1234567890123456',
        'password' => '123456',
        'password_confirmation' => '123456',
    ]);

    $response->assertSessionHasErrors('password');
});
